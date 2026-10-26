<?php

declare(strict_types=1);
namespace Drupal\commerce_paymos\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\commerce_paymos\Service\PaymentProcessor;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\{Request, Response};

final class WebhookController extends ControllerBase {
  public function __construct(protected PaymentProcessor $processor) {}
  public static function create(ContainerInterface $container): static {
    return new static($container->get('commerce_paymos.processor'));
  }
  public function notify(Request $request): Response {
    if (strlen($request->getContent())>1048576) { return new Response('',413); }
    $status = $this->processor->handle($request->getContent(),(string)$request->headers->get('X-Webhook-Signature'));
    return new Response($status===200?'OK':'Retry',$status,['Content-Type'=>'text/plain']);
  }
}
