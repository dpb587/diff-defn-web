<?php

namespace DPB\DiffDefnBundle\Event;

use Symfony\Component\EventDispatcher\Event;

class CommitDiffEvent extends Event
{
    protected $repo;
    protected $commit1;
    protected $commit2;

    public function __construct($repo, $commit1, $commit2)
    {
        $this->setName('commit_diff');

        $this->repo = $repo;
        $this->commit1 = $commit1;
        $this->commit2 = $commit2;
    }

    public function getRepo()
    {
        return $this->repo;
    }

    public function getCommit1()
    {
        return $this->commit1;
    }

    public function getCommit2()
    {
        return $this->commit2;
    }
}
