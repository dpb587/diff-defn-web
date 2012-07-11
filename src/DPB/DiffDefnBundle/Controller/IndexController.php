<?php

namespace DPB\DiffDefnBundle\Controller;

use DPB\DiffDefn\Manifest;
use DPB\DiffDefnBundle\Event\CommitDiffEvent;
use Symfony\Component\DependencyInjection\ContainerAware;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class IndexController extends ContainerAware
{
    public function indexAction()
    {
        return $this->container->get('templating')->renderResponse('DPBDiffDefnBundle:Index:index.html.twig');
    }

    public function compareAction(Request $request, $_format)
    {
        $response = new Response();
        $response->setETag(Manifest::VERSION);
        $response->setPublic();
        $response->setMaxAge(86400);
        $response->setSharedMaxAge(86400);

        if ($response->isNotModified($request)) {
            return $response;
        }

        try {
            $repo
                = $this->container
                ->get('dpb_diffdefn.repository_factory')
                ->create($request->query->get('repo'))
            ;
        } catch (\InvalidArgumentException $e) {
            throw new NotFoundHttpException(
                sprintf('The repository "%s" is unsupported.', $request->query->get('repo')),
                $e
            );
        }

        if (!$request->attributes->has('_internal')) {
            $links = $repo->getLinks();

            // vanity
            if (preg_match('#^https://github.com/([^/]+)/([^/]+)#', $links['web'], $match)) {
                return new RedirectResponse(
                    $this->container->get('router')->generate(
                        'dpb_diffdefn_github_compare',
                        array(
                            'repo' => $match[1] . '/' . $match[2],
                            'range' => $request->query->get('commit1') . '...' . $request->query->get('commit2'),
                        )
                    )
                );
            }
        }

        try {
            $commit1 = $repo->resolveCommit($request->query->get('commit1'));
        } catch (\InvalidArgumentException $e) {
            throw new NotFoundHttpException(
                sprintf('The first commit "%s" could not be resolved.', $request->query->get('commit1')),
                $e
            );
        }

        try {
            $commit2 = $repo->resolveCommit($request->query->get('commit2'));
        } catch (\InvalidArgumentException $e) {
            throw new NotFoundHttpException(
                sprintf('The second commit "%s" could not be resolved.', $request->query->get('commit2')),
                $e
            );
        }

        $s3 = $this->container->get('dpb_diffdefn.aws.s3');

        $bucket = $this->container->getParameter('dpb_diffdefn.aws.s3.bucket');
        $filename = Manifest::VERSION . '-' . md5(serialize($repo)) . '-' . $commit1[0] . '-' . $commit2[0] . '.xml';

        if (!$s3->if_object_exists($bucket, $filename)) {
            $res = $this->container->get('dpb_diffdefn.aws.sqs')->send_message(
                $this->container->getParameter('dpb_diffdefn.aws.sqs.url'),
                base64_encode(
                    serialize(
                        new CommitDiffEvent($repo, $commit1, $commit2)
                    )
                )
            );

            return new Response('Queued (' . $res->body->SendMessageResult->MessageId . ')', 202);
        }

        $res = $s3->get_object($bucket, $filename);

        if (!$res->isOK()) {
            throw new \RuntimeException('Race condition for non-existant s3 data.');
        }

        $diff = (string) $res->body;

        if ('xml' == $_format) {
            $response->setContent($diff);
            $response->headers->set('content-type', 'text/xml');
        } elseif ('html' == $_format) {
            $xsl = new \DOMDocument();
            $xsl->load($this->container->getParameter('kernel.root_dir') . '/../vendor/dpb587/diff-defn.php/src/DPB/DiffDefn/Resources/xsl/default.xsl');
    
            $xslt = new \XSLTProcessor();
            $xslt->registerPHPFunctions('str_replace');
            $xslt->importStylesheet($xsl);
    
            $xml = new \DOMDocument();
            $xml->loadXML($diff);
    
            $response->setContent($xslt->transformToXML($xml));
            $response->setStatusCode(200);
            $response->headers->set('content-type', 'text/html');
        } else {
            throw new \InvalidArgumentException('Unsupported format');
        }

        return $response;
    }
}
