<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tests\Support\WrittenColumns;

class WrittenColumnsTest extends TestCase
{
    public function test_insert_columns_are_extracted(): void
    {
        $this->assertSame(
            [['web_domain', 'domain'], ['web_domain', 'disable_symlinknotowner']],
            WrittenColumns::parse('insert into `web_domain` (`domain`, `disable_symlinknotowner`) values (?, ?)')
        );
        $this->assertSame([['client', 'a'], ['client', 'b']], WrittenColumns::parse('insert into "client" ("a", "b") values (?, ?), (?, ?)'));
    }

    public function test_update_assignments_are_extracted_without_the_where_clause(): void
    {
        $this->assertSame(
            [['client', 'company_name'], ['client', 'counter']],
            WrittenColumns::parse('update "client" set "company_name" = ?, "counter" = coalesce("counter", 0) + 1 where "client_id" = ?')
        );
    }

    public function test_reads_and_deletes_yield_nothing(): void
    {
        $this->assertSame([], WrittenColumns::parse('select * from client'));
        $this->assertSame([], WrittenColumns::parse('delete from client where client_id = 1'));
    }
}
