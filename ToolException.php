<?php

namespace Plugin\Mcp;

/**
 * A problem the assistant should see and can fix, such as a bad argument or a missing page.
 */
class ToolException extends \Exception
{
    protected $denied = false;

    /**
     * The token isn't allowed to do this. Logged as "denied".
     */
    public static function denied($message)
    {
        $e = new self($message);
        $e->denied = true;
        return $e;
    }

    public function isDenied()
    {
        return $this->denied;
    }
}
