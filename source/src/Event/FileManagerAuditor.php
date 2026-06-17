<?php

namespace App\Event;

use App\Entity\User;
use Symfony\Component\HttpFoundation\Request;

class FileManagerAuditor
{
    public function logWrite(?User $user, string $domain, string $path, int $bytes, ?Request $request = null) : void
    {
        if (null === $user) { return; }
        EventQueue::addEvent(EventQueue::EVENT_FILE_MANAGER_WRITE, $user, ['domain' => $domain, 'path' => $path, 'bytes' => $bytes], $request);
    }

    public function logDelete(?User $user, string $domain, string $path, ?Request $request = null) : void
    {
        if (null === $user) { return; }
        EventQueue::addEvent(EventQueue::EVENT_FILE_MANAGER_DELETE, $user, ['domain' => $domain, 'path' => $path], $request);
    }

    public function logUpload(?User $user, string $domain, string $path, int $bytes, ?Request $request = null) : void
    {
        if (null === $user) { return; }
        EventQueue::addEvent(EventQueue::EVENT_FILE_MANAGER_UPLOAD, $user, ['domain' => $domain, 'path' => $path, 'bytes' => $bytes], $request);
    }

    public function logRename(?User $user, string $domain, string $from, string $to, ?Request $request = null) : void
    {
        if (null === $user) { return; }
        EventQueue::addEvent(EventQueue::EVENT_FILE_MANAGER_RENAME, $user, ['domain' => $domain, 'from' => $from, 'to' => $to], $request);
    }

    public function logMkdir(?User $user, string $domain, string $path, ?Request $request = null) : void
    {
        if (null === $user) { return; }
        EventQueue::addEvent(EventQueue::EVENT_FILE_MANAGER_MKDIR, $user, ['domain' => $domain, 'path' => $path], $request);
    }
}
