<?php

namespace Drupal\invitation_qr\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Ensures the Twilio webhook route is never served from page cache
 * and is never blocked by Drupal's authentication subscriber.
 *
 * Without this, Drupal's PageCache middleware intercepts the POST
 * before the controller is ever called.
 */
class TwilioWebhookSubscriber implements EventSubscriberInterface {

  public static function getSubscribedEvents(): array {
    return [
      // Priority 350 runs BEFORE PageCache (priority 300) and
      // BEFORE AuthenticationSubscriber (priority 300).
      KernelEvents::REQUEST => [['onRequest', 350]],
    ];
  }

  public function onRequest(RequestEvent $event): void {
    $request = $event->getRequest();

    // Only act on the webhook path.
    if ($request->getPathInfo() !== '/invitation-qr/twilio-webhook') {
      return;
    }

    // Mark the request as not cacheable so PageCache passes it through.
    $request->setMethod($request->getMethod()); // no-op but forces request rebuild
    $request->headers->set('Cache-Control', 'no-cache');

    // Tell Drupal this is an API request — suppresses cookie-based
    // authentication checks that block anonymous POSTs.
    $request->attributes->set('_authentication_provider', 'cookie');
    $request->attributes->set('_disable_route_normalizer', TRUE);
  }

}
