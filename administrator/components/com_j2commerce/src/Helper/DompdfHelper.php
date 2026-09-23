<?php

/**
 * @package     J2Commerce
 * @subpackage  com_j2commerce
 *
 * @copyright   (C)2024-2026 J2Commerce, LLC <https://www.j2commerce.com>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace J2Commerce\Component\J2commerce\Administrator\Helper;

\defined('_JEXEC') or die;

use Dompdf\Dompdf;
use Dompdf\Options;
use J2Commerce\Component\J2commerce\Administrator\Helper\InvoiceHelper;
use J2Commerce\Component\J2commerce\Administrator\Helper\PackingSlipHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Uri\Uri;
use Joomla\Filesystem\Folder;

/**
 * Dompdf Helper class for J2Commerce
 *
 * Centralised Dompdf rendering for invoices, packing slips, and order print.
 * Any controller or plugin can call generateFromTemplate() to produce a PDF
 * from a J2Commerce invoice-template record.
 *
 * @since  6.0.1
 */
final class DompdfHelper
{
    /**
     * Singleton Dompdf instance (re-used across calls in the same request).
     *
     * @var   Dompdf|null
     * @since 6.0.1
     */
    private static ?Dompdf $dompdf = null;

    /**
     * Generate a PDF from a J2Commerce invoice/packing-slip/order template.
     *
     * @param   object  $order          The order object
     * @param   string  $templateType   Template type: 'invoice', 'order', 'packingslip'
     * @param   string  $receiverType   Receiver type passed to processTags()
     * @param   bool    $directOutput   If true, stream to browser; otherwise save to disk
     * @param   string  $fileName       Override filename (without path)
     *
     * @return  string|null  File path when saved, or null when streamed / on failure
     *
     * @since   6.0.1
     */
    public static function generateFromTemplate(
        object $order,
        string $templateType = 'invoice',
        string $receiverType = '*',
        bool $directOutput = false,
        string $fileName = ''
    ): ?string {
        $html = self::buildHtml($order, $templateType, $receiverType);

        if (empty($html)) {
            return null;
        }

        $dompdf = self::getDompdf();
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        $pdfBytes  = $dompdf->output();
        $safeName  = $fileName !== '' ? $fileName : self::buildFileName($order, $templateType);
        $basePath  = self::ensurePath();
        $filePath  = $basePath . '/' . $safeName;

        if (is_file($filePath)) {
            @unlink($filePath);
        }

        file_put_contents($filePath, $pdfBytes);

        if ($directOutput) {
            while (ob_get_level() > 0) {
                ob_end_clean();
            }

            header('Content-Type: application/pdf');
            header('Content-Disposition: inline; filename="' . addslashes($safeName) . '"');
            header('Content-Length: ' . \strlen($pdfBytes));
            header('Cache-Control: private, max-age=0, must-revalidate');
            header('Pragma: public');
            header('X-Content-Type-Options: nosniff');

            echo $pdfBytes;
            flush();
            exit(0);
        }

        return is_file($filePath) ? $filePath : null;
    }

    /**
     * Build the full HTML document for Dompdf rendering.
     *
     * @param   object  $order          The order object
     * @param   string  $templateType   Template type: 'invoice', 'order', 'packingslip'
     * @param   string  $receiverType   Receiver type passed to processTags()
     *
     * @return  string  Complete HTML document
     *
     * @since   6.0.1
     */
    public static function buildHtml(
        object $order,
        string $templateType = 'invoice',
        string $receiverType = '*'
    ): string {
        $templateHtml = self::loadTemplate($order, $templateType, $receiverType);

        if (empty($templateHtml)) {
            return '';
        }

        $templateHtml = self::embedImagesAsBase64($templateHtml);

        if (\extension_loaded('tidy')) {
            $tidyConfig = [
                'clean'           => true,
                'output-xhtml'    => true,
                'show-body-only'  => false,
                'wrap'            => 0,
                'indent'          => true,
                'char-encoding'   => 'utf8',
                'newline'         => 'LF',
                'output-bom'      => false,
                'tidy-mark'       => false,
                'drop-font-tags'  => false,
                'merge-divs'      => false,
                'merge-spans'     => false,
                'vertical-space'  => false,
                'wrap-attributes' => false,
            ];

            $tidy = new \tidy();
            $tidy->parseString($templateHtml, $tidyConfig, 'utf8');
            $tidy->cleanRepair();
            $templateHtml = (string) $tidy;
        }

        $templateHtml = preg_replace('/>\s+</', '><', $templateHtml);
        $templateHtml = htmlspecialchars_decode(htmlentities($templateHtml, ENT_COMPAT, 'UTF-8'), ENT_COMPAT);

        $title = ucfirst($templateType) . ' ' . ($order->order_id ?? '');

        return '<!DOCTYPE html>
<html>
<head>
<meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
<title>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</title>
</head>
<body>' . $templateHtml . '</body>
</html>';
    }

    /**
     * Load a processed template by type.
     *
     * @param   object  $order          The order object
     * @param   string  $templateType   Template type: 'invoice', 'order', 'packingslip'
     * @param   string  $receiverType   Receiver type passed to processTags()
     *
     * @return  string  Processed template HTML
     *
     * @since   6.0.1
     */
    public static function loadTemplate(
        object $order,
        string $templateType = 'invoice',
        string $receiverType = '*'
    ): string {
        switch ($templateType) {
            case 'order':
                return InvoiceHelper::getInstance()->getFormattedOrder($order, $receiverType);

            case 'packingslip':
                return PackingSlipHelper::getInstance()->getFormattedPackingSlip($order, $receiverType);

            case 'invoice':
            default:
                return InvoiceHelper::getInstance()->getFormattedInvoice($order, $receiverType);
        }
    }

    /**
     * Get or initialise the Dompdf instance.
     *
     * @return  Dompdf
     *
     * @since   6.0.1
     */
    public static function getDompdf(): Dompdf
    {
        if (self::$dompdf === null) {
            self::$dompdf = self::initDompdf();
        }

        return self::$dompdf;
    }

    /**
     * Initialise a Dompdf instance with J2Commerce defaults.
     *
     * @return  Dompdf
     *
     * @since   6.0.1
     */
    public static function initDompdf(): Dompdf
    {
        $autoloadPaths = [
            JPATH_LIBRARIES . '/dompdf/vendor/autoload.php',
            JPATH_LIBRARIES . '/dompdf/autoload.inc.php',
        ];

        foreach ($autoloadPaths as $path) {
            if (is_file($path)) {
                require_once $path;
                break;
            }
        }

        $options = new Options();
        $options->setIsFontSubsettingEnabled(true);
        $options->setIsRemoteEnabled(true);
        $options->setChroot(JPATH_ROOT);

        $config = Factory::getApplication()->getConfig();
        $options->setLogOutputFile($config->get('log_path') . '/dompdf_log.html');

        $dompdf = new Dompdf($options);
        $dompdf->setBasePath(JPATH_SITE . '/');

        return $dompdf;
    }

    /**
     * Build a safe filename for the generated PDF.
     *
     * @param   object  $order          The order object
     * @param   string  $templateType   Template type used as prefix
     *
     * @return  string  Filename (e.g. 'invoice_INV2026-001.pdf')
     *
     * @since   6.0.1
     */
    public static function buildFileName(object $order, string $templateType): string
    {
        $prefix = $templateType;

        try {
            $invoiceNumber = InvoiceHelper::getInvoiceNumber($order);
            $suffix = $invoiceNumber ?: ($order->order_id ?? '');
        } catch (\Throwable $e) {
            $suffix = $order->order_id ?? '';
        }

        return $prefix . '_' . $suffix . '.pdf';
    }

    /**
     * Ensure the PDF output directory exists and is protected.
     *
     * @return  string  Absolute path to the PDF directory
     *
     * @since   6.0.1
     */
    public static function ensurePath(): string
    {
        $path = JPATH_ROOT . '/media/j2commerce/pdfs';

        if (!is_dir($path)) {
            Folder::create($path);
        }

        $htaccess = $path . '/.htaccess';

        if (!is_file($htaccess)) {
            file_put_contents($htaccess, "Deny from all\n");
        }

        $indexHtml = $path . '/index.html';

        if (!is_file($indexHtml)) {
            file_put_contents($indexHtml, '<!DOCTYPE html><title></title>');
        }

        return $path;
    }

    /**
     * Convert image src URLs to base64 data URIs for PDF embedding.
     *
     * @param   string  $html  HTML content with image tags
     *
     * @return  string  HTML with images converted to data URIs
     *
     * @since   6.0.1
     */
    public static function embedImagesAsBase64(string $html): string
    {
        $baseUrl = rtrim(str_replace('/administrator', '', Uri::base()), '/');

        return (string) preg_replace_callback(
            '/(<img[^>]+src=")(\[^\"]+)(")/i',
            function (array $matches) use ($baseUrl): string {
                $url = $matches[2];

                if (str_starts_with($url, 'data:')) {
                    return $matches[0];
                }

                $filePath = self::resolveImagePath($url, $baseUrl);

                if ($filePath === null || !is_file($filePath)) {
                    return $matches[0];
                }

                $mime = mime_content_type($filePath) ?: 'image/png';
                $data = base64_encode((string) file_get_contents($filePath));

                if ($data === false) {
                    return $matches[0];
                }

                return $matches[1] . 'data:' . $mime . ';base64,' . $data . $matches[3];
            },
            $html
        );
    }

    /**
     * Resolve an image URL to a local file path.
     *
     * @param   string  $url      The image URL
     * @param   string  $baseUrl  The site base URL
     *
     * @return  string|null  Absolute file path, or null if not local
     *
     * @since   6.0.1
     */
    private static function resolveImagePath(string $url, string $baseUrl): ?string
    {
        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            if (!str_starts_with($url, $baseUrl)) {
                return null;
            }

            $relative = substr($url, \strlen($baseUrl));
            $relative = ltrim($relative, '/');

            return JPATH_ROOT . '/' . $relative;
        }

        $relative = ltrim($url, '/');

        return JPATH_ROOT . '/' . $relative;
    }
}
