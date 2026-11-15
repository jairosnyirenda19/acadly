<?php

declare(strict_types=1);

namespace Acadly\Kernel\Notify\Channels;

use Acadly\Kernel\Notify\Channel;
use LogicException;

/** The adapter for each channel in this deployment. */
final class ChannelAdapters
{
    /** @var array<string, ChannelAdapter> */
    private array $adapters = [];

    /** @param  iterable<ChannelAdapter>  $adapters */
    public function __construct(iterable $adapters)
    {
        foreach ($adapters as $adapter) {
            $this->adapters[$adapter->channel()->value] = $adapter;
        }
    }

    public function for(Channel $channel): ChannelAdapter
    {
        return $this->adapters[$channel->value] ?? throw new LogicException("No adapter is bound for [{$channel->value}].");
    }
}
