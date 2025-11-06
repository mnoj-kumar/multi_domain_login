<?php

namespace Drupal\multi_domain_login\Controller;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Component\Utility\Crypt;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\PageCache\ResponsePolicy\KillSwitch;
use Drupal\Core\Routing\TrustedRedirectResponse;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Site\Settings;
use Drupal\Core\Url;
use Drupal\user\UserInterface;
use Symfony\Component\HttpFoundation\Request;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Returns responses for Login routes.
 *
 * Login multiple domains:
 *
 * Example:
 * domain A
 * domain B
 * domain C
 *
 * Step 1 - login current domain
 *
 * - Do normal drupal login at domain A: http://www.a.com/user/login
 * - hook_user_login is called after user has logged in.
 *   generate a random hash for domain B (hash-b) and C (hash-c).
 *   store in 'key_value' with hash as key and current uid as value.
 *   redirect to: http://www.a.com/user/login/domain
 *
 * Step 2 - http://www.a.com/user/login/domain page
 * - does a call in page to each domain from iframe
 *     http://www.b.com/user/login/domain/uid/timestamp/hash-b
 *     http://www.c.com/user/login/domain/uid/timestamp/hash-c
 * - after success on all domains redirect to homepage of original domain
 *   (event bubble):
 *     http://www.a.com
 * - if failure to login, logout
 *     http://www.a.com/logout
 *
 * Step 3 - http://www.x.com/user/login/domain/hash-x
 * - lookup hash-x in 'key_value' storage to retrieve uid.
 * - login user
 * - remove hash-x from storage
 * - return ok
 */
class MultiDomainLoginController extends ControllerBase implements ContainerInjectionInterface {

  /**
   * A logger instance.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * The page cache disabling policy.
   *
   * @var \Drupal\Core\PageCache\ResponsePolicy\KillSwitch
   */
  protected $pageCacheKillSwitch;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected $currentUser;

  /**
   * The module handler service.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * The request object.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected $requestStack;

  /**
   * @var \Drupal\Component\Datetime\TimeInterface
   */
  protected TimeInterface $time;

  /**
   * Constructs a UserController object.
   *
   * @param \Psr\Log\LoggerInterface $logger
   *   A logger instance.
   * @param \Drupal\Core\PageCache\ResponsePolicy\KillSwitch $pageCacheKillSwitch
   *   The page cache disabling policy.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time.
   * @param \Drupal\Core\Session\AccountProxyInterface $current_user
   *   The current user.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler service.
   * @param \Symfony\Component\HttpFoundation\RequestStack $request_stack
   *   The request stack object.
   */
  public function __construct(LoggerInterface $logger,
                              KillSwitch $pageCacheKillSwitch,
                              TimeInterface $time,
                              AccountProxyInterface $current_user,
                              ModuleHandlerInterface $module_handler,
                              RequestStack $request_stack) {
    $this->logger = $logger;
    $this->pageCacheKillSwitch = $pageCacheKillSwitch;
    $this->time = $time;
    $this->currentUser = $current_user;
    $this->moduleHandler = $module_handler;
    $this->requestStack = $request_stack->getCurrentRequest();
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('logger.factory')->get('multi_domain_login'),
      $container->get('page_cache_kill_switch'),
      $container->get('datetime.time'),
      $container->get('current_user'),
      $container->get('module_handler'),
      $container->get('request_stack')
    );
  }

  /**
   * The domain function.
   *
   * Entry point after the user_login hook, starts the redirect flow
   * through all the domains by redirecting to the first domain login route.
   * We keep hold of the initial domain making the request (referrer) and store
   * it in the url as a crc32 encoded value to prevent any possible url
   * encoding.
   */
  public function domain(Request $request) {
    $this->pageCacheKillSwitch->trigger();

    $referrer = $this->getRequestDomain($request);

    // Get the language of the current request, we will use it to generate
    // the translated success or error urls when we return.
    $language = $this->languageManager()->getCurrentLanguage(LanguageInterface::TYPE_URL);

    $url = $this->loginUrl($request, $referrer, $language->getId(), TRUE);

    $response = new TrustedRedirectResponse($url, 303);
    $response->addCacheableDependency((new CacheableMetadata())
      ->setCacheMaxAge(0)
    );

    return $response;
  }

  /**
   * The login function.
   *
   * A domain specific login. Incoming is the referrer and the domain on
   * which we want to login.
   */
  public function login($referrer, $uid, $timestamp, $hash, $langcode, Request $request) {
    $this->pageCacheKillSwitch->trigger();

    // Do the login on the current domain.
    $this->doLogin($uid, $timestamp, $hash);

    // Get the next login url (or the initial home page).
    $url = $this->loginUrl($request, $referrer, $langcode, FALSE);

    $response = new TrustedRedirectResponse($url, 303);
    $response->addCacheableDependency((new CacheableMetadata())
      ->setCacheMaxAge(0)
    );

    return $response;
  }

  /**
   * Helper to generate a domain specific login url.
   */
  protected function loginUrl($request, $referrer, $langcode, $skip_referrer_check) {

    // Get the current domain.
    $current = $this->getRequestDomain($request);

    $timestamp = $this->time->getRequestTime();
    $user = $this->currentUser;

    /** @var \Drupal\user\UserInterface $account */
    $account = $this->entityTypeManager()->getStorage('user')->load($user->id());

    // Get the domains we need to redirect to.
    $domains = $this->getDomains();

    // Look for the current domain.
    while (crc32(current($domains)) != $current) {
      next($domains);
    }
    // Take the next in the array, or loop back to the first.
    $domain = next($domains) ?: reset($domains);

    if (!$skip_referrer_check && crc32($domain) == $referrer) {
      // Generate a language independent path to the domain.
      $urlToRedirectONSuccess = \Drupal::config('multi_domain_login.settings')->get('redirect_success');
      if (empty($urlToRedirectONSuccess)) {
        $url = Url::fromRoute('<front>');
      }
      else {
        $url = Url::fromUserInput($urlToRedirectONSuccess);
      }

      $url = $url->setAbsolute()
        ->setOption('language', $this->languageManager()->getLanguage($langcode))
        ->toString(TRUE)
        ->getGeneratedUrl();

      // Url will be in the current domain, replace that with
      // the requested domain.
      $url = str_replace($domains, $domain, $url);
    }
    else {
      // Generate the login url for the next domain.
      // Generate the hash for this user.
      $hash = $this->hash($account, $timestamp);

      // Generate a language independent path to login to the domain.
      $url = Url::fromRoute('multi_domain_login.login', [
        'referrer' => $referrer,
        'uid' => $account->id(),
        'timestamp' => $timestamp,
        'hash' => $hash,
        'langcode' => $langcode,
      ])->setAbsolute()
        ->toString(TRUE)
        ->getGeneratedUrl();

      // Url will be in the current domain, replace that with
      // the requested domain.
      $url = str_replace($domains, $domain, $url);
    }

    // Let other modules alter the url if needed.
    $this->moduleHandler->alter('multi_domain_login_url', $url, $domain);

    return $url;
  }

  /**
   * The getRequestDomain function.
   */
  protected function getRequestDomain(Request $request) {

    // Fallback to the current host if not found in the domains list.
    $domain = $request->getSchemeAndHttpHost();

    // Get the domains we need to redirect to.
    $domains = $this->getDomains();

    // Determine the domain based on the current request.
    foreach ($domains as $aDomain) {
      if (str_starts_with($request->getUri(), $aDomain)) {
        $domain = $aDomain;
        break;
      }
    }

    // Let other modules alter the current domain if needed.
    $this->moduleHandler->alter('multi_domain_login_domain', $domain, $domains);

    return crc32($domain);
  }

  /**
   * Helper to get the domains we need to login to.
   *
   * @return array|mixed|null
   *   The Domains.
   */
  protected function getDomains() {
    // Get the domains we need to redirect to.
    $domains = $this->config('multi_domain_login.settings')->get('domains');
    $this->moduleHandler->alter('multi_domain_login_domains', $domains);
    return $domains;
  }

  /**
   * The doLogin function.
   */
  protected function doLogin($uid, $timestamp, $hash) {

    $enableExtraLogging = $this->config('multi_domain_login.settings')->get('enable_extra_logging');
    $currentHost = $this->requestStack->getHost();

    if ($this->timeout($timestamp)) {
      $status = 403;
      $this->logger->critical('Login attempt expired @domain', ['@domain' => $currentHost]);
    }
    else {
      /** @var \Drupal\user\UserInterface $user */
      $user = $this->entityTypeManager()->getStorage('user')->load($uid);

      // Verify that the user exists and is active.
      if ($user === NULL || !$user->isActive()) {
        // Blocked or invalid user ID, so deny access. The parameters will be in
        // the watchdog's URL for the administrator to check.
        $status = 403;
        $this->logger->warning('User @uid no longer active or found @domain', [
          '@uid' => $uid,
          '@domain' => $currentHost,
        ]);
      }
      else {

        $current_user = $this->currentUser;
        $force_logout = $this->config('multi_domain_login.settings')->get('force_logout');

        if ($current_user->isAuthenticated() && !$force_logout) {
          $this->logger->info('User @uid already logged in @domain', [
            '@uid' => $uid,
            '@domain' => $currentHost,
          ]);
          $status = 200;
        }
        elseif ($current_user->isAuthenticated() && $force_logout) {
          user_logout();
          if ($enableExtraLogging) {
            $this->logger->debug('User logout @domain', [
              '@domain' => $currentHost,
            ]);
          }
        }

        // Log in with our requested user.
        if ($current_user->isAnonymous()) {
          if (hash_equals($hash, $this->hash($user, $timestamp))) {
            user_login_finalize($user);
            $status = 200;
            if ($enableExtraLogging) {
              $this->logger->debug('Login finalize: 200 @domain', [
                '@domain' => $currentHost,
              ]);
            }
          }
          else {
            $status = 403;
            $this->logger->critical('Invalid hash used in login attempt @domain', [
              '@domain' => $currentHost,
            ]);
          }
        }
      }
    }

    return $status;
  }

  /**
   * The hash function.
   */
  protected function hash(UserInterface $account, $timestamp) {
    $data = $timestamp;
    $data .= $account->id();
    $data .= $account->getEmail();
    return Crypt::hmacBase64($data, Settings::getHashSalt() . $account->getPassword());
  }

  /**
   * The timeout function.
   */
  protected function timeout($timestamp) {
    // The current user is not logged in, so check the parameters.
    $current = $this->time->getRequestTime();

    // Time out, in seconds, until login URL expires.
    $timeout = $this->config('multi_domain_login.settings')->get('timeout');

    return ($current - $timestamp > $timeout);
  }

}
