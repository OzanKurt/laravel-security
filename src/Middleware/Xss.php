<?php

namespace OzanKurt\Security\Middleware;

use Closure;
use OzanKurt\Security\Abstracts\Middleware;
use OzanKurt\Security\Events\AttackDetectedEvent;
use OzanKurt\Security\Security;
use OzanKurt\Security\Helpers\BladeEchoCleaner;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\ParameterBag;

class Xss extends Middleware
{
    public function __construct(
        protected Security $security,
        protected BladeEchoCleaner $bladeEchoCleaner,
    ) { }

    /**
     * Handle an incoming request.
     *
     * @param \Illuminate\Http\Request $request
     * @param \Closure $next
     * @return mixed
     */
    public function handle($request, Closure $next)
    {
        if ($this->skip($request)) {
            return $next($request);
        }

        $mode = config('security.middleware.xss.mode', 'block');

        $this->clean($request);

        if ($mode === 'block') {
            if ($this->check($this->getPatterns())) {
                return $this->respond(config('security.responses.block'));
            }
        }

        return $next($request);
    }

    public function check($patterns)
    {
        $log = null;

        if ($this->security->antiXss->isXssFound()) {
            $log = $this->log();

            event(new AttackDetectedEvent($log));
        } else {
            foreach ($patterns as $pattern) {
                if (! $match = $this->match($pattern, $this->request->input())) {
                    continue;
                }

                $log = $this->log();

                event(new AttackDetectedEvent($log));

                break;
            }
        }

        if ($log) {
            return true;
        }

        return false;
    }

    /**
     * Clean the request's data.
     *
     * @param \Illuminate\Http\Request $request
     * @return void
     */
    protected function clean($request)
    {
        $this->cleanParameterBag($request->query);

        if ($request->isJson()) {
            $this->cleanParameterBag($request->json());
        } elseif ($request->request !== $request->query) {
            $this->cleanParameterBag($request->request);
        }
    }

    /**
     * Clean the data in the parameter bag.
     *
     * @param \Symfony\Component\HttpFoundation\ParameterBag $bag
     * @return void
     */
    protected function cleanParameterBag(ParameterBag $bag)
    {
        $bag->replace($this->cleanArray($bag->all()));
    }

    /**
     * Clean the data in the given array.
     *
     * @param array $data
     * @param string $keyPrefix
     * @return array
     */
    protected function cleanArray(array $data, $keyPrefix = '')
    {
        foreach ($data as $key => $value) {
            $data[$key] = $this->cleanValue($keyPrefix . $key, $value);
        }

        return $data;
    }

    /**
     * Clean the given value.
     *
     * @param string $key
     * @param mixed $value
     * @return mixed
     */
    protected function cleanValue($key, $value)
    {
        if (is_array($value)) {
            return $this->cleanArray($value, $key . '.');
        }

        return $this->transform($key, $value);
    }

    /**
     * Transform the given value.
     *
     * @param  string  $key
     * @param  mixed  $value
     * @return mixed
     */
    protected function transform($key, $value)
    {
        $output = $this->security->cleanInput((string) $value);

        if (! config('security.middleware.xss.allow_blade_echoes')) {
            $output = $this->bladeEchoCleaner->clean((string) $output);
        }

        if ($output === $value) {
            return $output;
        }

        $mode = config('security.middleware.xss.mode', 'block');

        return $mode === 'clean' ? null : $value;
    }
}
