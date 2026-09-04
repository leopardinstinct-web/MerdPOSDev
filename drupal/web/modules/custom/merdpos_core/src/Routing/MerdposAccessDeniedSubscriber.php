<?php

declare(strict_types=1);

namespace Drupal\merdpos_core\Routing;

use Drupal\Core\Routing\CurrentRouteMatch;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/** Redirects anonymous MERDPOS app access to the authoritative login screen. */
final class MerdposAccessDeniedSubscriber implements EventSubscriberInterface {

  public function __construct(
    private readonly AccountProxyInterface $currentUser,
    private readonly CurrentRouteMatch $routeMatch,
  ) {}

  public static function getSubscribedEvents(): array {
    return [KernelEvents::EXCEPTION => ['onException', 75]];
  }
  public function onException(ExceptionEvent $event): void {
    if ($this->currentUser->isAuthenticated()) return;
    if (!$event->getThrowable() instanceof AccessDeniedHttpException) return;
    $routeName = (string) $this->routeMatch->getRouteName();
    if (!str_starts_with($routeName, 'merdpos_core.')) return;

    $request = $event->getRequest();
    $destination = $request->getRequestUri();
    $login = Url::fromRoute('user.login', [], [
      'query' => ['destination' => $destination],
      'absolute' => TRUE,
    ])->toString();
    $event->setResponse(new RedirectResponse($login, 302));
  }

}
