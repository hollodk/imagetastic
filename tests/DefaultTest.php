<?php

namespace Translator\Test;

use PHPUnit\Framework\TestCase;

final class DefaultTest extends TestCase
{
    public function testTypos()
    {
        $imagetastic = new \Imagetastic\Client('key', 'project');

        // first test, just to make sure we dont have any typos
        $this->assertEquals(1, 1);
    }
}
