<?php

namespace App\EventListener;

use App\System\Configuration\ConfigStore;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

readonly class LocaleListener implements EventSubscriberInterface
{

    public function __construct(private ConfigStore $configStore, private string $defaultLocale = 'en')
    {
    }

    private function setRequestLocale(Request $request, $locale): void
    {
        $this->configStore->router->setLocale($locale);
        $request->setLocale($request->getSession()->get('_locale', $this->defaultLocale));
        $request->getSession()->set('_locale', $locale);
    }

    /**
     * @param \Symfony\Component\HttpKernel\Event\RequestEvent $event
     *
     * @return void
     */
    public function onKernelRequest(RequestEvent $event)
    {
        $request = $event->getRequest();
        if ($locale = $request->attributes->get('_locale')) {
            $this->setRequestLocale($request, $locale);
            return;
        }

        $this->configStore->configureApplications();

        $uri      = parse_url($request->getUri(), PHP_URL_PATH);
        $uriParts = explode('/', ltrim($uri, '/'));
        if (count($uriParts) > 2) {
            array_pop($uriParts);
        }

        $uri    = '/' . implode('/', $uriParts);
        $locale = $request->getSession()->get('_locale', $this->defaultLocale);
        try {
            $route  = $this->configStore->router->match($uri);
            $locale = $route->getLocale();
        } catch (NotFoundHttpException) {
        }

        if ($locale == '_default') {
            return;
        }

        $this->setRequestLocale($request, $locale);
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => [['onKernelRequest', 20]], // higher prio than kernel localelistener
        ];
    }
}