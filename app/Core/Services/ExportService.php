<?php

namespace App\Core\Services;

use Barryvdh\DomPDF\Facade\Pdf;
use Maatwebsite\Excel\Facades\Excel;
use Illuminate\Support\Collection;

/**
 * Export Service
 *
 * Handles exporting data to PDF and Excel formats.
 */
class ExportService
{
    /**
     * Export data to PDF.
     *
     * @param string $view Blade view name
     * @param array $data Data to pass to view
     * @param string $filename Output filename
     * @param string $orientation 'portrait' or 'landscape'
     * @param string $size Paper size (A4, letter, etc.)
     */
    public function toPdf(
        string $view,
        array $data,
        string $filename,
        string $orientation = 'portrait',
        string $size = 'a4',
        array $options = []
    ) {
        $pdf = Pdf::loadView($view, $data)
            ->setPaper($size, $orientation);

        if ($options) {
            $pdf->setOption($options);
        }

        return $pdf->download($filename . '.pdf');
    }

    /**
     * Stream PDF to browser.
     */
    public function streamPdf(
        string $view,
        array $data,
        string $filename,
        string $orientation = 'portrait',
        string $size = 'a4'
    ) {
        $pdf = Pdf::loadView($view, $data)
            ->setPaper($size, $orientation);

        return $pdf->stream($filename . '.pdf');
    }

    /**
     * Export data to Excel.
     *
     * @param mixed $export Export class instance
     * @param string $filename Output filename
     */
    public function toExcel($export, string $filename)
    {
        return Excel::download($export, $filename . '.xlsx');
    }

    /**
     * Export collection to simple Excel.
     */
    public function collectionToExcel(
        Collection $collection,
        string $filename,
        array $headers = []
    ) {
        return Excel::download(
            new class($collection, $headers) implements \Maatwebsite\Excel\Concerns\FromCollection, \Maatwebsite\Excel\Concerns\WithHeadings {
                public function __construct(
                    protected Collection $collection,
                    protected array $headers
                ) {}

                public function collection()
                {
                    return $this->collection;
                }

                public function headings(): array
                {
                    return $this->headers;
                }
            },
            $filename . '.xlsx'
        );
    }

    /**
     * Generate invoice PDF.
     */
    public function generateInvoicePdf(array $invoiceData)
    {
        return $this->toPdf(
            'exports.invoice',
            $invoiceData,
            'invoice-' . $invoiceData['invoice_no'],
            'portrait',
            'a4'
        );
    }

    /**
     * Generate job card PDF.
     */
    public function generateJobCardPdf(array $jobCardData)
    {
        return $this->toPdf(
            'exports.job-card',
            $jobCardData,
            'job-card-' . $jobCardData['job_number'],
            'portrait',
            'a4'
        );
    }

    /**
     * Generate daily collection report PDF.
     */
    public function generateCollectionReportPdf(array $reportData)
    {
        return $this->toPdf(
            'exports.daily-collection',
            $reportData,
            'collection-report-' . $reportData['date'],
            'landscape',
            'a4'
        );
    }
}
