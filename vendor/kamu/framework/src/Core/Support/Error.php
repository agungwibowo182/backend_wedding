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
    protected $nameFileLog = '/kamu.log';
    protected $locationFileLog = '/cache/log';

    private $information;
    private $stream;
    private $throwable;

    public function __construct()
    {
        $this->stream = fopen('php://stderr', 'wb');
    }

    public function __destruct()
    {
        if (is_resource($this->stream)) {
            fclose($this->stream);
        }
        $this->stream = null;
    }

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
            }, $th->getTrace())
        ], 0, 1024);
    }

    protected function view(string $path, array $data = []): View
    {
        $view = App::get()->singleton(View::class);
        $view->variables($data);
        $view->show($path);
        return $view;
    }

    public function setThrowable(Throwable $t): Error
    {
        $this->throwable = $t;
        return $this;
    }

    public function getThrowable(): Throwable
    {
        return $this->throwable;
    }

    public function getInformation(): string
    {
        return strval($this->information);
    }

    public function setInformation(string|null $information): Error
    {
        if ($information) {
            $this->information = $information;
        }
        return $this;
    }

    public function report(): Error
    {
        if (!$this->information) {
            $this->setInformation($this->transformToJson($this->throwable));
        }

        if (env('LOG', 'true') === 'false') {
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

        // ✅ Gunakan folder sementara
        $logDirectory = sys_get_temp_dir() . '/log';

        if (!is_dir($logDirectory)) {
            if (!mkdir($logDirectory, 0777, true) && !is_dir($logDirectory)) {
                error_log("Failed to create log directory: $logDirectory");
                return $this;
            }
        }

        $logFilePath = $logDirectory . $this->nameFileLog;

        $status = @file_put_contents(
            $logFilePath,
            $this->getInformation() . PHP_EOL,
            FILE_APPEND | LOCK_EX
        );

        if (!$status) {
            if (is_resource($this->stream)) {
                fwrite($this->stream, "[LOG FAIL] Gagal simpan ke $logFilePath\n" . $this->getInformation() . PHP_EOL);
            }

            error_log("Fallback log: " . $this->getInformation());
        }

        return $this;
    }

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
