<?php

namespace DPB\DiffDefnBundle\Event;

use DPB\DiffDefn\Definition\DefnDefinition;
use DPB\DiffDefn\Definition\RootDefinition;
use DPB\DiffDefn\Dumper\XmlDumper;
use DPB\DiffDefn\Manifest;
use DPB\DiffDefn\Util\Processor;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\Event;

class CommitDiffHandler extends Event
{
    protected $container;

    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;
    }

    public function handle(CommitDiffEvent $event, OutputInterface $output)
    {
        $repo = $event->getRepo();
        $commit1 = $event->getCommit1();
        $commit2 = $event->getCommit2();


        $s3 = $this->container->get('dpb_diffdefn.aws.s3');

        $bucket = $this->container->getParameter('dpb_diffdefn.aws.s3.bucket');
        $filename = Manifest::VERSION . '-' . md5(serialize($repo)) . '-' . $commit1[0] . '-' . $commit2[0] . '.xml';

        if ($s3->if_object_exists($bucket, $filename)) {
            // handled by another thread
            return true;
        }

        $output->writeln(sprintf('Comparing <info>%s</info> to <info>%s</info>', $commit1[0], $commit2[0]));

        $diffs = $repo->getChangedFiles($commit1[0], $commit2[0]);

        $output->writeln('Found ' . count($diffs) . ' changed file' . (1 != count($diffs) ? 's' : ''));

        $links = $repo->getLinks();
        $attrs = array(
            'repository' => '/dev/null',
        );

        if (isset($links['web'])) {
            $attrs['repository-link'] = $links['web'];
        }

        if (isset($links['file'])) {
            $attrs['file-link'] = $links['file'];
        }

        if (isset($links['commit'])) {
            $attrs['commit-link'] = $links['commit'];
        }

        $defnRepository = new DefnDefinition('source', $attrs);

        $beginScope = new RootDefinition('root');
        $beginScope->assert($defnRepository)->assert(new DefnDefinition('commit', array('value' => $commit1[0], 'friendly' => $commit1[1])));

        $finalScope = new RootDefinition('root');
        $finalScope->assert($defnRepository)->assert(new DefnDefinition('commit', array('value' => $commit2[0], 'friendly' => $commit2[1])));

        foreach ($diffs as $file) {
            $finalScope->setAttribute('file', $file);
            $beginScope->setAttribute('file', $file);

            $output->write($file . '...');

            if ('php' != pathinfo($file, PATHINFO_EXTENSION)) {
                $output->writeln('skipped');

                continue;
            }

            try {

                Processor::process(
                    $finalScope,
                    $repo->getFileContent(
                        $commit2[0],
                        $file
                    )
                );

                $output->write('...');

                Processor::process(
                    $beginScope,
                    $repo->getFileContent(
                        $commit1[0],
                        $file
                    )
                );

                $output->writeln('done');
            } catch (\PHPParser_Error $e) {
                $output->writeln('<error>error</error>');
                $output->writeln('  ' . $e->getMessage());
            }
        }

        $finalScope->unsetAttribute('file');
        $beginScope->unsetAttribute('file');

        $dumper = new XmlDumper();
        $comparator = new \DPB\DiffDefn\Comparator\DefinitionComparator();

        $diff = $dumper->dump($comparator->compare($finalScope, $beginScope));

        $res = $s3->create_object(
            $bucket,
            $filename,
            array(
                'body' => $diff,
                'length' => mb_strlen($diff),
            )
        );

        return $res->isOK();
    }
}
