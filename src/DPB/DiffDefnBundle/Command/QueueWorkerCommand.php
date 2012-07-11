<?php

namespace DPB\DiffDefnBundle\Command;

use DPB\DiffDefn\Definition\DefnDefinition;
use DPB\DiffDefn\Definition\RootDefinition;
use DPB\DiffDefn\Dumper\XmlDumper;
use DPB\DiffDefn\Util\Processor;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class QueueWorkerCommand extends ContainerAwareCommand
{
    protected function configure()
    {
        $this
            ->setName('diff-defn:queue:worker')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $container = $this->getContainer();

        $sqs = $container->get('dpb_diffdefn.aws.sqs');
        $sqsurl = $container->getParameter('dpb_diffdefn.aws.sqs.url');

        $res = $sqs->receive_message(
            $sqsurl,
            array(
                'MaxNumberOfMessages' => 1,
                'VisibilityTimeout' => 5,
            )
        );

        if (isset($res->body->ReceiveMessageResult->Message)) {
            $output->writeln(sprintf('==== <info>%s</info>', (string) $res->body->ReceiveMessageResult->Message->MessageId));
    
            $obj = unserialize(base64_decode((string) $res->body->ReceiveMessageResult->Message->Body));
    
            $result = $container->get('dpb_diffdefn.event.' . $obj->getName())->handle($obj, $output);
    
            if (!$result) {
                return 1;
            }
    
            $sqs->delete_message($sqsurl, (string) $res->body->ReceiveMessageResult->Message->ReceiptHandle);
        }
    }
}
