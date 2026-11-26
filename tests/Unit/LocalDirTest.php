<?php

namespace Tests\Unit;

use App\Domain\Backup\LocalDir;
use PHPUnit\Framework\TestCase;

class LocalDirTest extends TestCase
{
    public function test_a_relative_backup_folder_is_taken_from_the_application_folder(): void
    {
        $base = '/home/shop/aushvan';
        $this->assertSame('/home/shop/aushvan'.DIRECTORY_SEPARATOR.'../aushvan-backups', LocalDir::resolve('../aushvan-backups', $base, '/default'));
        $this->assertSame('/home/shop/aushvan'.DIRECTORY_SEPARATOR.'backups', LocalDir::resolve('backups', $base.'/', '/default'));
    }

    public function test_absolute_folders_and_the_default_are_left_alone(): void
    {
        $this->assertSame('/var/backups', LocalDir::resolve('/var/backups', '/app', '/default'));
        $this->assertSame('E:\\AUSHVAN\\backups', LocalDir::resolve('E:\\AUSHVAN\\backups', 'C:\\app', '/default'));
        $this->assertSame('C:/Users/x/AppData/Local/AUSHVAN/backups', LocalDir::resolve('C:/Users/x/AppData/Local/AUSHVAN/backups', 'C:\\app', '/default'));
        $this->assertSame('/default', LocalDir::resolve(null, '/app', '/default'));
        $this->assertSame('/default', LocalDir::resolve('  ', '/app', '/default'));
    }
}
