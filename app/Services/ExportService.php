<?php
declare(strict_types=1);
namespace FoodRescue\Services;
final class ExportService
{
    public function csv(array $rows): string
    {
        $stream=fopen('php://temp','r+');
        $headers=[];foreach ($rows as $row) { foreach (array_keys($row) as $key) { if (!in_array($key,$headers,true)) { $headers[]=$key; } } }
        if ($rows) { fputcsv($stream,$headers,',','"',''); }
        foreach ($rows as $row) {
            $values=[];foreach ($headers as $key) {
                $value=$row[$key] ?? null;
                if (is_array($value)) { $value=json_encode($value,JSON_THROW_ON_ERROR); }
                $value=(string)($value ?? '');if (preg_match('/^[\s]*[=+@-]/u',$value)) { $value="'" . $value; }$values[]=$value;
            }fputcsv($stream,$values,',','"','');
        }rewind($stream);$csv=stream_get_contents($stream);fclose($stream);return $csv;
    }
    public function pdf(string $title,array $data): string
    {
        $dompdf=new \Dompdf\Dompdf(['isRemoteEnabled'=>false,'isPhpEnabled'=>false,'chroot'=>BASE_PATH . '/storage/exports','tempDir'=>BASE_PATH . '/storage/exports','fontCache'=>BASE_PATH . '/storage/exports']);
        $chart='';
        if (isset($data['runs'])) {
            $chart='<h2>Simulated pickup success rate</h2><table style="width:100%">';
            foreach ($data['runs'] as &$run) {
                $rate=$run['metrics']['pickup_success_rate'];
                $chart.='<tr><td style="width:25%">' . e($run['algorithm']) . '</td><td style="width:60%"><div style="height:15px;background:#245a40;width:' . ($rate===null ? 0 : max(0,min(100,$rate*100))) . '%"></div></td><td>' . ($rate===null ? 'N/A' : number_format($rate*100,1) . '%') . '</td></tr>';
                $run['decision_count']=count($run['decisions']);unset($run['decisions']);
            }unset($run);$chart.='</table><p>Outcomes in this comparison are simulated, including experiments using imported input scenarios.</p>';
            unset($data['payload']);
        }
        $html='<html><head><meta charset="UTF-8"><style>body{font-family:DejaVu Sans,sans-serif;font-size:10px;color:#173e30}h1{font-size:22px}pre{white-space:pre-wrap;overflow-wrap:break-word;font-size:9px}</style></head><body><h1>' . e($title) . '</h1><p>Generated ' . gmdate('c') . ' · FoodRescue</p>' . $chart . '<pre>' . e(json_encode($data,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)) . '</pre></body></html>';
        $dompdf->loadHtml($html);$dompdf->setPaper('A4');$dompdf->render();return $dompdf->output();
    }
}
