<?php

namespace App\Contracts;

use App\Data\KnowledgeRetrievalRequest;
use App\Data\KnowledgeRetrievalResult;

interface KnowledgeRetrievalProvider
{
    public function retrieve(KnowledgeRetrievalRequest $request): KnowledgeRetrievalResult;
}