<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class IdentifyResponder
{
    /**
     * Adds headers identifying the host responding to the request so we can
     * trace which pod/node served a given request in Kubernetes.
     *
     * Relies on standard env vars (settable via the K8s downward API):
     *  - POD_NAME, POD_IP, POD_NAMESPACE
     *  - NODE_NAME
     * Falls back to gethostname() (which K8s sets to the pod name by default).
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $hostname = gethostname() ?: 'unknown';
        $podName = env('POD_NAME', $hostname);
        $podIp = env('POD_IP');
        $podNamespace = env('POD_NAMESPACE');
        $nodeName = env('NODE_NAME');

        $response->headers->set('X-Hostname', $hostname);
        $response->headers->set('X-Pod-Name', $podName);

        if ($podIp) {
            $response->headers->set('X-Pod-IP', $podIp);
        }

        if ($podNamespace) {
            $response->headers->set('X-Pod-Namespace', $podNamespace);
        }

        if ($nodeName) {
            $response->headers->set('X-Node-Name', $nodeName);
        }

        return $response;
    }
}
