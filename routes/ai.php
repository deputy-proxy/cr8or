<?php

use App\Http\Middleware\AssignCorrelationId;
use App\Mcp\Servers\Cr8orServer;
use Laravel\Mcp\Facades\Mcp;

Mcp::oauthRoutes();

Mcp::web('/mcp', Cr8orServer::class)
    ->middleware([AssignCorrelationId::class, 'auth:api']);
