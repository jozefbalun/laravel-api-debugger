<?php namespace Lanin\ApiDebugger;

use Illuminate\Database\Connection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Events\Dispatcher as Event;
use Illuminate\Support\Facades\Log;
use phpDocumentor\Reflection\Types\Object_;
use Symfony\Component\HttpFoundation\Response;

class Debugger
{

    /**
     * @var Collection
     */
    private $queries;

    /**
     * @var Event
     */
    private $event;

    /**
     * @var Collection
     */
    private $debug;

    /**
     * @var bool
     */
    private $collectQueries = false;
    /**
     * @var Connection
     */
    private $db;

    /**
     * @var int
     */
    private $totalQueriesTime;

    /**
     * Create a new Debugger service.
     *
     * @param Event $event
     * @param Connection $connection
     */
    public function __construct(Event $event, Connection $connection)
    {
        $this->queries            = new Collection();
        $this->debug              = new Collection();
        $this->event              = $event;
        $this->db                 = $connection;
        $this->totalQueriesTime   = 0;

        $this->event->listen('kernel.handled', function ($request, $response) {
            $this->updateRequest($request, $response);
        });
    }

    /**
     * Listen database queries events.
     */
    public function collectDatabaseQueries()
    {
        $this->collectQueries = true;

        $this->db->listen(function ($event) {
            $this->logQuery($event->sql, $event->bindings, $event->time);
        });
    }

    /**
     * Log DB query.
     *
     * @param string $query
     * @param array $attributes
     * @param float $time
     */
    private function logQuery($query, $attributes, $time)
    {
        $query = vsprintf(str_replace(['%', '?'], ['%%', "'%s'"], $query), $attributes) . ';';

        $this->queries->push([
            'query' => $query,
            'time'  => $time
        ]);

        $this->totalQueriesTime += $time;
    }

    /**
     * Add vars to debug output.
     */
    public function dump()
    {
        foreach (func_get_args() as $var) {
            $this->debug->push($var);
        }
    }

    /**
     * Update final response.
     *
     * @param Request $request
     * @param Response $response
     */
    private function updateRequest(Request $request, Response $response)
    {
        if ($response instanceof \Dingo\Api\Http\Response && $this->needToUpdateResponse()) {
            $data = json_decode($response->getContent());

            if (!$data) {
                $data = new \stdClass();
                $data->debug = new \stdClass();
            }

            if ($this->collectQueries) {
                $data->debug->sql = (object)[
                    'total_queries' => $this->queries->count(),
                    'total_queries_time' => $this->totalQueriesTime,
                    'queries' => $this->queries,
                ];
            }

            if (!$this->debug->isEmpty()) {
                $data->debug->dump = (object)$this->debug;
            }

            $response->setContent(json_encode($data));
        } elseif ($response instanceof JsonResponse && $this->needToUpdateResponse()) {
            $data = $response->getData(true);
            if ($this->collectQueries) {
                $data['debug']['sql'] = [
                    'total_queries' => $this->queries->count(),
                    'total_queries_time' => $this->totalQueriesTime,
                    'queries' => $this->queries,
                ];
            }
            if (!$this->debug->isEmpty()) {
                $data['debug']['dump'] = $this->debug;
            }
            $response->setData($data);
        }
    }

    /**
     * Check if debugger has to update the response.
     *
     * @return bool
     */
    private function needToUpdateResponse()
    {
        return $this->collectQueries || !$this->debug->isEmpty();
    }
}
