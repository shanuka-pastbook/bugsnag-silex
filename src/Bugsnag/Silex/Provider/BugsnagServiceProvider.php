<?php
namespace Bugsnag\Silex\Provider;

use Silex\Application;
use Silex\ServiceProviderInterface;

class BugsnagServiceProvider implements ServiceProviderInterface
{
    private static $request;

    public function register(Application $app)
    {
        $app['bugsnag'] = $app->share(function () use($app) {
            $client = new Bugsnag_Client($app['bugsnag.options']['apiKey']);
            set_error_handler(array($bugsnag, 'errorhandler'));
            set_exception_handler(array($bugsnag, 'exceptionhandler'));
            return $client;
        });

        /* Captures the request's information */
        $app->before(function ($request) {
            self::$request = $request;
        });

        $app->error(function (Exception $error, $code) use($app) {
            $app['bugsnag']->setBeforeNotifyFunction($this->filterFramesFunc());

            $session = self::$request->getSession();
            if ($session) {
                $session = $session->all();
            }

            $qs = array();
            parse_str(self::$request->getQueryString(), $qs);

            $app['bugsnag']->notifyException($error, array(
                "request" => array(
                    "clientIp" => self::$request->getClientIp(),
                    "params" => $qs,
                    "requestFormat" => self::$request->getRequestFormat(),
                ),
                "session" => $session,
                "cookies" => self::$request->cookies->all(),
                "host" => array(
                    "hostname" => self::$request->getHttpHost()
                )
            ));
        });
    }

    public function boot(Application $app)
    {}

    private function filterFramesFunc()
    {
        return function (Bugsnag_Error $error) {
            $frames = array_filter($error->stacktrace->frames, function ($frame) {
                $file = $frame['file'];

                if (preg_match('/^\[internal\]/', $file))
                    return FALSE;
                if (preg_match('/symfony\/http-kernel/', $file))
                    return FALSE;
                if (preg_match('/silex\/silex\//', $file))
                    return FALSE;

                return TRUE;
            });

            $error->stacktrace->frames = array();
            foreach ($frames as $frame) {
                $error->stacktrace->frames[] = $frame;
            }

            // We do it here, raw error object doesn't have this method.
            $error->setMetaData(array(
                "user" => array(
                    "clientIp" => self::$request->getClientIp()
                )
            ));
        };
    }
}