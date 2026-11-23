---
view: components.packages.quick-look
title: A quick look
subtitle: One route per URI, a controller per version, and a v2-only endpoint.
file: routes/api.php
language: php
code: |
  Route::get('users/{user}', 'UserController@show')->controllers([
      'v1' => 'UserV1Controller@show',
      'v2' => 'UserV2Controller@show',
  ]);

  Route::sinceVersion('v2')->middleware('auth:api')->group(function () {
      Route::apiResource('reports', 'ReportController');
  });

  // GET /api/users/1   X-API-Version: v2   → UserV2Controller@show
---
