<?php

namespace Drupal\multi_domain_login\EventSubscriber;

use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Url;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Drupal\multi_domain_login\Event\UserLoginEvent;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * The UserLoginSubscriber Class.
 *
 * @package Drupal\multi_domain_login\EventSubscriber
 */
class UserLoginSubscriber implements EventSubscriberInterface {

  /**
   * The language manager.
   *
   * @var \Drupal\Core\Language\LanguageManagerInterface
   */
  private $languageManager;

  /**
   * The current route match.
   *
   * @var \Drupal\Core\Routing\RouteMatchInterface
   */
  private $routeMatch;

  /**
   * The request stack.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  private $requestStack;

  /**
   * UserLoginSubscriber constructor.
   *
   * @param \Drupal\Core\Language\LanguageManagerInterface $languageManager
   *   The language manager.
   * @param \Drupal\Core\Routing\RouteMatchInterface $routeMatch
   *   The current route match.
   * @param \Symfony\Component\HttpFoundation\RequestStack $requestStack
   *   The request stack.
   */
  public function __construct(LanguageManagerInterface $languageManager,
                              RouteMatchInterface $routeMatch,
                              RequestStack $requestStack) {
    $this->languageManager = $languageManager;
    $this->routeMatch = $routeMatch;
    $this->requestStack = $requestStack;
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    return [
      // -100 means very low priority.
      UserLoginEvent::EVENT_NAME => ['onUserLogin', -100],
    ];
  }

  /**
   * Subscribe to the user login event dispatched.
   *
   * @param \Drupal\multi_domain_login\Event\UserLoginEvent $event
   *   Event object.
   */
  public function onUserLogin(UserLoginEvent $event) {
    $current_route = $this->routeMatch->getRouteName();

    if (!in_array($current_route, [
      'user.reset',
      'multi_domain_login.login',
    ])) {
      $current_request = $this->requestStack->getCurrentRequest();
      $destination = Url::fromRoute('multi_domain_login.domain')
        ->toString(TRUE)
        ->getGeneratedUrl();
      $current_request->query->set('destination', $destination);
    }
  }

}
