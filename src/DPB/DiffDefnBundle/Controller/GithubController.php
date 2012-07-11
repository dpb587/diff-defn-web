<?php

namespace DPB\DiffDefnBundle\Controller;

use DPB\DiffDefn\Manifest;
use DPB\DiffDefnBundle\Event\CommitDiffEvent;
use Symfony\Component\DependencyInjection\ContainerAware;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class GithubController extends ContainerAware
{
    public function compareAction(Request $request, $repo, $range)
    {
        if (false === strpos($range, '...')) {
            throw new NotFoundHttpException('Unsupported range');
        }

        list($commit1, $commit2) = explode('...', $range);

        return $this->container->get('http_kernel')->forward(
            'DPBDiffDefnBundle:Index:compare',
            array(
                '_format' => $request->getRequestFormat(),
                '_internal' => 'github',
            ),
            array(
                'repo' => 'git://github.com/' . $repo . '.git',
                'commit1' => $commit1,
                'commit2' => $commit2,
            )
        );
    }
}
