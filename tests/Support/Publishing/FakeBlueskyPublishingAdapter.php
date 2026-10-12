<?php

namespace Tests\Support\Publishing;

final class FakeBlueskyPublishingAdapter extends FakeNetworkPublishingAdapter
{
    protected function networkKey(): string
    {
        return 'bsky';
    }
}