<?php

namespace App\Http\Controllers;

use App\Models\ServiceNowRecord;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Quatrebarbes\SnowDriver\Exceptions\ServiceNowApiException;
use Quatrebarbes\SnowDriver\Exceptions\ServiceNowConnectionException;
use Quatrebarbes\SnowDriver\Exceptions\ServiceNowMalformedResponseException;
use Throwable;

/**
 * Application de démo (Phase 5 de la roadmap) : parcourt n'importe quelle
 * table du menu au travers du driver ServiceNow, sans modèle dédié par table.
 */
class ServiceNowTableController extends Controller
{
    public function index(): View
    {
        return view('tables.index', [
            'tables' => config('servicenow_demo.tables'),
        ]);
    }

    public function show(Request $request, string $table): View|Response
    {
        $this->guardKnownTable($table);

        $page = max(1, (int) $request->query('page', 1));
        $pageSize = (int) config('servicenow_demo.page_size', 20);

        try {
            $records = ServiceNowRecord::forTable($table)
                ->newQuery()
                ->orderByDesc('sys_created_on')
                ->skip(($page - 1) * $pageSize)
                ->take($pageSize)
                ->get();
        } catch (ServiceNowApiException|ServiceNowConnectionException|ServiceNowMalformedResponseException $e) {
            return $this->errorView($table, $e);
        }

        return view('tables.show', [
            'table' => $table,
            'label' => $this->labelFor($table),
            'columns' => $this->columnsFor($table, $records),
            'records' => $records,
            'page' => $page,
            'hasMore' => $records->count() === $pageSize,
        ]);
    }

    public function record(string $table, string $sysId): View|Response
    {
        $this->guardKnownTable($table);

        try {
            $record = ServiceNowRecord::forTable($table)->newQuery()->find($sysId);
        } catch (ServiceNowApiException|ServiceNowConnectionException|ServiceNowMalformedResponseException $e) {
            return $this->errorView($table, $e);
        }

        return view('tables.record', [
            'table' => $table,
            'label' => $this->labelFor($table),
            'sysId' => $sysId,
            'record' => $record,
        ]);
    }

    private function guardKnownTable(string $table): void
    {
        abort_unless(array_key_exists($table, config('servicenow_demo.tables', [])), 404);
    }

    private function labelFor(string $table): string
    {
        return config("servicenow_demo.tables.{$table}.label", $table);
    }

    /**
     * @return array<int, string>
     */
    private function columnsFor(string $table, Collection $records): array
    {
        $configured = config("servicenow_demo.tables.{$table}.columns");

        if ($configured) {
            return $configured;
        }

        $first = $records->first();

        return $first ? array_slice(array_keys($first->getAttributes()), 0, 6) : [];
    }

    private function errorView(string $table, Throwable $e): Response
    {
        $status = match (true) {
            $e instanceof ServiceNowApiException => $e->statusCode(),
            $e instanceof ServiceNowConnectionException => 503,
            default => 502,
        };

        return response()->view('tables.error', [
            'table' => $table,
            'label' => $this->labelFor($table),
            'exception' => $e,
        ], $status);
    }
}
