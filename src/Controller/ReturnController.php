<?php

declare(strict_types=1);

namespace Drupal\commerce_paymos\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\EventSubscriber\RedirectResponseSubscriber;
use Drupal\Core\Routing\LocalRedirectResponse;
use Drupal\commerce_paymos\Service\ReturnBridge;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

final class ReturnController extends ControllerBase {
  public function __construct(private ReturnBridge $bridge, private RedirectResponseSubscriber $redirects) {}

  public static function create(ContainerInterface $container): static {
    return new static($container->get('commerce_paymos.return_bridge'), $container->get('redirect_response_subscriber'));
  }

  public function handle(Request $request): LocalRedirectResponse {
    $this->redirects->setIgnoreDestination(TRUE);
    return new LocalRedirectResponse($this->bridge->target($request->query->getBoolean('cancel')), 302,
      ['Cache-Control' => 'private, no-store, max-age=0']);
  }
}
