<?php

namespace App;

use App\EventListener\LocaleListener;
use App\System\Configuration\ConfigStoreBase;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\Finder\Finder;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    /** @var string */
    protected $publicDir;

    public function __construct(string $environment, bool $debug, string $publicDir)
    {
        parent::__construct($environment, $debug);
        $this->publicDir = $publicDir;
    }

    public function registerBundles(): iterable
    {
        yield new \Symfony\Bundle\FrameworkBundle\FrameworkBundle();
        yield new \Symfony\Bundle\TwigBundle\TwigBundle();
        yield new \Doctrine\Bundle\DoctrineBundle\DoctrineBundle();
        yield new \Symfony\Bundle\SecurityBundle\SecurityBundle();
        yield new \Symfony\Bundle\MonologBundle\MonologBundle();

        if ($this->getEnvironment() == 'dev') {
            yield new \Symfony\Bundle\DebugBundle\DebugBundle();
            yield new \Symfony\Bundle\WebProfilerBundle\WebProfilerBundle();
        }
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $unchainedConfig = new ConfigStoreBase($this->getProjectDir())->getUnchainedConfig();
        $locale          = $_ENV['LOCALE'] ?? $unchainedConfig->getLocale();

        $container->parameters()->set('kernel.public_dir', $this->publicDir);
        $container->parameters()->set('timezone', '%env(string:TZ)%');
        $container->parameters()->set('unchained.locales', $unchainedConfig->getLocales());
        $container->parameters()->set('default_locale', $locale);
        $container->parameters()->set('unchained.config', $unchainedConfig->toArray());


        $container->import(__DIR__ . '/../config/framework.yaml');
        $container->import(__DIR__ . '/../config/packages/cache.yaml');
        $container->import(__DIR__ . '/../config/packages/doctrine.yaml');
        $container->import(__DIR__ . '/../config/packages/security.yaml');
        $container->import(__DIR__ . '/../config/packages/twig.yaml');

        $envConfig = Finder::create()->files()->in(__DIR__ . '/../config/packages/' . $this->getEnvironment())->name('*.yaml');
        foreach ($envConfig as $file) {
            $container->import($file->getRealPath());
        }
        $userConfig = Finder::create()->files()->in(__DIR__ . '/../user/config/framework')->name('*.yaml');
        if ($userConfig->hasResults()) {
            foreach ($userConfig as $file) {
                $container->import($file->getRealPath());
            }
        }
        // configure WebProfilerBundle only if the bundle is enabled
        if (isset($this->bundles['WebProfilerBundle'])) {
            $container->extension('web_profiler', [
                'toolbar'             => true,
                'intercept_redirects' => false,
            ]);
        }

        $services = $container->services();
        $services->defaults()
            ->autowire()
            ->autoconfigure()
            ->private()
            ->bind('$projectDir', $this->getProjectDir())
            ->bind('$publicDir', $this->publicDir);

        $services->load('App\\', __DIR__ . '/../src')
            ->exclude([__DIR__ . '/../src/Kernel.php']);

        $services->load('App\\Controller\\', __DIR__ . '/../src/Controller')
            ->tag('controller.service_arguments');

        // LocaleListener needs the default locale
        $services->set(LocaleListener::class)
            ->arg('$defaultLocale', '%default_locale%')
            ->autoconfigure();
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        // import the WebProfilerRoutes, only if the bundle is enabled
        if (isset($this->bundles['WebProfilerBundle'])) {
            $routes->import('@WebProfilerBundle/Resources/config/routing/wdt.php', 'php')->prefix('/_wdt');
            $routes->import('@WebProfilerBundle/Resources/config/routing/profiler.php', 'php')->prefix('/_profiler');
        }

        // load the routes defined as PHP attributes
        // (use 'annotation' as the second argument if you define routes as annotations)
        $routes->import(__DIR__ . '/Controller/', 'attribute');
    }

    public function boot(): void
    {
        parent::boot();
        if ($this->container && $this->container->hasParameter('timezone')) {
            date_default_timezone_set($this->container->getParameter('timezone'));
        }
    }

    // optional, to use the standard Symfony cache directory
    public function getCacheDir(): string
    {
        return __DIR__ . '/../var/cache/' . $this->getEnvironment();
    }

    // optional, to use the standard Symfony logs directory
    public function getLogDir(): string
    {
        return __DIR__ . '/../var/log';
    }
}