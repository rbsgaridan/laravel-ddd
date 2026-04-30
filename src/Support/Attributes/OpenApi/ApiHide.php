<?php

namespace Incoder\DDD\Support\Attributes\OpenApi;

use Attribute;

/**
 * Excludes a controller class or individual method from the generated API docs.
 * Equivalent to FastAPI's `include_in_schema=False`.
 *
 * Usage:
 *   #[ApiHide]
 *   class InternalController extends Controller { ... }
 *
 *   #[ApiHide]
 *   public function internalCallback(Request $request) { ... }
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
class ApiHide {}
