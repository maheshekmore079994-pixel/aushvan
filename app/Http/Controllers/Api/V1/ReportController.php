<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Reports\DashboardData;
use App\Domain\Reports\ReportExporter;
use App\Domain\Reports\ReportLabels;
use App\Domain\Reports\ReportRunner;
use App\Domain\Shared\Audit;
use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ReportController extends Controller
{
    /** Home screen numbers; open to every signed-in user, each block filtered by permission inside. */
    public function dashboard(Request $request, DashboardData $dashboard): JsonResponse
    {
        return response()->json($dashboard->for($request->user()));
    }

    public function index(): JsonResponse
    {
        $reports = [];
        foreach (ReportRunner::REPORTS as $key => $definition) {
            $reports[] = ['key' => $key] + $definition;
        }

        return response()->json(['data' => $reports]);
    }

    public function show(Request $request, string $report, ReportRunner $runner): JsonResponse
    {
        return response()->json($runner->run($request->user(), $report, $this->filters($request, $report)));
    }

    public function export(Request $request, string $report, ReportRunner $runner, ReportExporter $exporter): BinaryFileResponse
    {
        $filters = $this->filters($request, $report);
        $options = $request->validate([
            'format' => ['required', Rule::in(array_keys(ReportExporter::FORMATS))],
            'lang' => ['nullable', Rule::in(config('shop.languages'))],
        ]);

        $user = $request->user();
        $result = $runner->run($user, $report, $filters);
        $path = $exporter->write($result, $options['format'], $user->store, new ReportLabels($options['lang'] ?? $user->locale));

        try {
            // Data leaving the system is worth a record of who took it and when.
            Audit::log('reports.exported', null, new: ['report' => $report, 'format' => $options['format'], 'rows' => count($result['rows'])] + $result['filters']);
        } catch (\Throwable $e) {
            @unlink($path);
            throw $e;
        }

        return response()->download($path, $exporter->fileName($result, $options['format']), ['Content-Type' => ReportExporter::FORMATS[$options['format']]])
            ->deleteFileAfterSend();
    }

    /** @return array<string, mixed> */
    private function filters(Request $request, string $report): array
    {
        abort_unless(isset(ReportRunner::REPORTS[$report]), 404);

        return $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'type' => ['nullable', Rule::in([Product::TYPE_MEDICAL, Product::TYPE_GENERAL])],
            'days' => ['nullable', 'integer', 'min:1', 'max:730'],
        ]);
    }
}
