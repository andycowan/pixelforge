<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\BuildOperatorReport;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\OperatorReportRequest;
use Inertia\Inertia;
use Inertia\Response;

final class OperatorReportController extends Controller
{
    public function __invoke(OperatorReportRequest $request, BuildOperatorReport $report): Response
    {
        return Inertia::render('admin/Report', ['report' => $report->execute(...$request->range())]);
    }
}
