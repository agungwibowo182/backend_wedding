<?php

namespace Core\Support;

use Core\Database\Exception\DatabaseException;
use Core\Facades\App;
use Core\Http\Respond;
use Core\View\View;
use DateTimeImmutable;
use Exception;
use Throwable;

/**
 * Error reporting.
 *
 * @class Error
 * @package \Core\Support
 */
class Error
{
    /**
     * Nama file dari log nya.
     *
     * @var string $nameFileLog
     */
    protected $nameFileLog = '/kamu.log';

    /**
     * Nama folder dari log nya.
     *
     * @var string $locationFileLog
     */
    protected $locationFileLog = '/cache/log';

    /**
     * Informasi dalam json.
     *
     * @var string $information
     */
    private $information;

    /**
     * Stream stderr.
     *
     * @var resource|null
     */
    private $stream;

    /**
     * Throwable object.
     *
     * @var Throwable
     */
    private $throwable;

    /**
     * Init object.
     *
     * @return void
     */
    public function __construct()
    {
        $this->stream = fopen('php://stderr', 'wb');
    }

    /**
     * Destroy object.
     *
     * @return void
     */
    public function __destruct()
    {
        if (is_resource($this->stream)) {
            fclose($this->stream);
        }

        $this->stream = null;
    }

    /**
     * Ubah ke JSON.
     *
     * @param Throwable $th
     * @return string
     */
    private function transformToJson(Throwable $th): string
    {
        return json_encode([
            'message' => $th->getMessage(),
            'sql' => ($th instanceof DatabaseException) ? $th->getQueryString() : null,
            'database' => ($th instanceof DatabaseException) ? $th->getInfoDriver() : null,
            'file' => $th->getFile(),
            'line' => $th->getLine(),
            'code' => $th->getCode(),
            'date' => now(DateTimeImmutable::RFC3339_EXTENDED),
            'duration' => execute_time(),
            'trace' => array_map(function (array $data): array {
                unset($data['args']);
                return $data;
            },  $th->getTrace())
        ], 0, 1024);
    }

    /**
     * View template html.
     *
     * @param string $path
     * @param array $data
     * @return View
     */
    protected function view(string $path, array $data = []): View
    {
        $view = App::get()->singleton(View::class);
        $view->variables($data);
        $view->show($path);

        return $view;
    }

    /**
     * Set Throwable.
     *
     * @param Throwable $t
     * @return Error
     */
    public function setThrowable(Throwable $t): Error
    {
        $this->throwable = $t;
        return $this;
    }

    /**
     * Get Throwable.
     *
     * @return Throwable
     */
    public function getThrowable(): Throwable
    {
        return $this->throwable;
    }

    /**
     * Dapatkan informasi dalam json.
     *
     * @return string
     */
    public function getInformation(): string
    {
        return strval($this->information);
    }

    /**
     * Set infomasi dengan format json.
     *
     * @param string|null $information
     * @return Error
     */
    public function setInformation(string|null $information): Error
    {
        if ($information) {
            $this->information = $information;
        }

        return $this;
    }

    /**
     * Laporkan errornya.
     *
     * @return Error
     */
    public function report(): Error
    {
        if (!$this->information) {
            $this->setInformation($this->transformToJson($this->throwable));
        }

        if (env('LOG', 'true') == 'false') {
            return $this;
        }

        if (is_resource($this->stream)) {
            fwrite($this->stream, sprintf(
                '[%s] (%s) %s::%s %s',
                now(DateTimeImmutable::RFC3339_EXTENDED),
                execute_time(),
                $this->throwable->getFile(),
                $this->throwable->getLine(),
                $this->throwable->getMessage()
            ) . PHP_EOL);
        }

        // ✅ Ganti lokasi log ke /tmp/log agar dapat ditulis di environment terbatas
        $this->locationFileLog = '/tmp/log';

        $logDirectory = base_path($this->locationFileLog);
        if (!is_dir($logDirectory)) {
            if (!mkdir($logDirectory, 0777, true) && !is_dir($logDirectory)) {
                error_log("Failed to create log directory: $logDirectory");
                return $this;
            }
        }

        $logFilePath = base_path($this->locationFileLog . $this->nameFileLog);
        $status = @file_put_contents(
            $logFilePath,
            $this->getInformation() . PHP_EOL,
            FILE_USE_INCLUDE_PATH | FILE_APPEND | LOCK_EX
        );

        if (!$status) {
            // Fallback log ke stderr
            if (is_resource($this->stream)) {
                fwrite($this->stream, "[LOG FAIL] Gagal simpan ke $logFilePath\n" . $this->getInformation() . PHP_EOL);
            }

            // Fallback ke log PHP default
            error_log("Fallback log: " . $this->getInformation());

            return $this;
        }

        return $this;
    }



    /**
     * Show error to dev.
     *
     * @return mixed
     */
    public function render(): mixed
    {
        if (!debug()) {
            return unavailable();
        }

        respond()->clean();
        respond()->setCode(Respond::HTTP_INTERNAL_SERVER_ERROR);

        if (!request()->ajax()) {
            return render(helper_path('/errors/trace'), ['error' => $this->throwable]);
        }

        respond()->getHeader()->set('Content-Type', 'application/json');
        return $this->getInformation();
    }
}
