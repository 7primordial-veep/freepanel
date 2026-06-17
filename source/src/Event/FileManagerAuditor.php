<?php

namespace App\Event;

use App\Entity\User;
use Symfony\Component\HttpFoundation\Request;

class FileManagerAuditor
{
    public function logWrite(?User $user, string $domain, string $path, int $bytes, ?Request $request = null) : void
    { EventQueue::addEvent('file_manager.write', $user, ['domain'=>$domain, 'path'=>$path, 'bytes'=>$bytes], $request); }

    public function logDelete(?User $user, string $domain, string $path, ?Request $request = null) : void
    { EventQueue::addEvent('file_manager.delete', $user, ['domain'=>$domain, 'path'=>$path], $request); }

    public function logUpload(?User $user, string $domain, string $path, int $bytes, ?Request $request = null) : void
    { EventQueue::addEvent('file_manager.upload', $user, ['domain'=>$domain, 'path'=>$path, 'bytes'=>$bytes], $request); }

    public function logRename(?User $user, string $domain, string $from, string $to, ?Request $request = null) : void
    { EventQueue::addEvent('file_manager.rename', $user, ['domain'=>$domain, 'from'=>$from, 'to'=>$to], $request); }

    public function logMkdir(?User $user, string $domain, string $path, ?Request $request = null) : void
    { EventQueue::addEvent('file_manager.mkdir', $user, ['domain'=>$domain, 'path'=>$path], $request); }
}
