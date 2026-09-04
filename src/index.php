<?php
/**
 * Programación IV — Simulador Visual e Interactivo de Arquitecturas de Software
 * 
 * Este archivo contiene el backend PHP (API Handler) y la interfaz frontend (HTML5 + CSS3 + JS)
 * para simular y analizar 7 arquitecturas de software mediante el caso de uso "Crear Pedido / Checkout".
 * 
 * Entorno: Apache 2.4 + PHP 8.4 + PostgreSQL 16 (Docker)
 */

// -----------------------------------------------------------------------------
// INICIALIZACIÓN Y CONEXIÓN A POSTGRESQL
// -----------------------------------------------------------------------------
$dbHost = getenv('DB_HOST') ?: 'db';
$dbPort = getenv('DB_PORT') ?: '5432';
$dbName = getenv('DB_NAME') ?: 'app_db';
$dbUser = getenv('DB_USER') ?: 'app_user';
$dbPass = getenv('DB_PASS') ?: 'app_secret_password';

$dbConnected = false;
$dbErrorMsg = '';
$pdo = null;

try {
    $dsn = "pgsql:host={$dbHost};port={$dbPort};dbname={$dbName};";
    $pdo = new PDO($dsn, $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_TIMEOUT => 2
    ]);
    
    // Crear tabla de pedidos si no existe
    $pdo->exec("CREATE TABLE IF NOT EXISTS pedidos (
        id SERIAL PRIMARY KEY,
        cliente VARCHAR(100) NOT NULL,
        producto VARCHAR(150) NOT NULL,
        cantidad INT NOT NULL,
        total NUMERIC(10, 2) NOT NULL,
        arquitectura VARCHAR(50) NOT NULL,
        estado VARCHAR(50) NOT NULL,
        fecha_registro TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    
    $dbConnected = true;
} catch (PDOException $e) {
    $dbConnected = false;
    $dbErrorMsg = $e->getMessage();
}

// -----------------------------------------------------------------------------
// BACKEND: API ENDPOINT (Manejo de peticiones POST)
// -----------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    
    // Leer JSON de la petición
    $rawInput = file_get_contents('php://input');
    $data = json_decode($rawInput, true) ?? [];
    
    $architecture = $data['architecture'] ?? 'monolithic';
    $simulateDbFailure = !empty($data['simulate_db_failure']);
    $orderData = $data['order_data'] ?? [
        'cliente' => 'Juan Pérez',
        'producto' => 'Laptop Gamer RTX 4080',
        'cantidad' => 1,
        'precio_unitario' => 1850.00
    ];

    $response = runArchitectureSimulation($architecture, $simulateDbFailure, $orderData);
    
    http_response_code($response['http_code']);
    echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Motor de Simulación Backend de Arquitecturas
 */
function runArchitectureSimulation(string $arch, bool $failDb, array $order) {
    $total = $order['cantidad'] * $order['precio_unitario'];
    $orderId = 'ORD-' . strtoupper(substr(md5(uniqid()), 0, 8));
    $steps = [];
    $startTime = microtime(true);
    
    switch ($arch) {
        // ---------------------------------------------------------------------
        // 1. MONOLÍTICA EN CAPAS (N-Tier)
        // ---------------------------------------------------------------------
        case 'monolithic':
            $steps[] = createStep(
                nodeId: 'node-ui',
                layer: 'Presentación (UI / Request)',
                component: 'OrderWebController',
                class: 'App\\Controllers\\OrderController::store()',
                action: 'Recibe la petición HTTP POST /pedidos con datos de formulario de checkout.',
                latency: 4,
                status: 'success'
            );
            
            $steps[] = createStep(
                nodeId: 'node-controller',
                layer: 'Capa de Control',
                component: 'OrderController',
                class: 'App\\Controllers\\OrderController::validateAndPass()',
                action: 'Valida tipos de datos de entrada y delega ejecución directamente a la capa de negocio.',
                latency: 8,
                status: 'success'
            );

            $steps[] = createStep(
                nodeId: 'node-service',
                layer: 'Lógica de Negocio (Service Layer)',
                component: 'OrderService',
                class: 'App\\Services\\OrderService::createOrder()',
                action: "Calcula subtotal (\${$total}) y aplica reglas de negocio síncronas directas.",
                latency: 15,
                status: 'success'
            );

            if ($failDb) {
                $steps[] = createStep(
                    nodeId: 'node-dao',
                    layer: 'Acceso a Datos (DAO)',
                    component: 'OrderDAO',
                    class: 'App\\DAO\\OrderDAO::insert()',
                    action: 'Intenta abrir conexión PDO con PostgreSQL en app_db:5432...',
                    latency: 22,
                    status: 'error',
                    detail: 'CRITICAL ERROR: SQLSTATE[08006] [7] connection to server at "db" (172.20.0.3), port 5432 failed: Connection refused.'
                );
                
                $steps[] = createStep(
                    nodeId: 'node-db',
                    layer: 'Base de Datos',
                    component: 'PostgreSQL DB',
                    class: 'pg_connect()',
                    action: '🔴 Conexión rechazada. Excepción no capturada colapsa la aplicación.',
                    latency: 0,
                    status: 'error',
                    detail: 'Fatal Exception: Uncaught PDOException in /var/www/html/src/DAO/OrderDAO.php:45 Stack trace: #0 OrderService.php(28) #1 OrderController.php(14)'
                );

                return [
                    'http_code' => 500,
                    'status' => 'ERROR 500 - Internal Server Error',
                    'message' => 'Colapso fatal del monolito por fallo en la capa de datos. El error de BD no fue abstraído.',
                    'order_id' => $orderId,
                    'total_time_ms' => round((microtime(true) - $startTime) * 1000, 2),
                    'steps' => $steps,
                    'db_persisted' => false
                ];
            } else {
                $persisted = tryPersistRealOrder($orderId, $order, $total, 'Monolito en Capas');
                $steps[] = createStep(
                    nodeId: 'node-dao',
                    layer: 'Acceso a Datos (DAO)',
                    component: 'OrderDAO',
                    class: 'App\\DAO\\OrderDAO::insert()',
                    action: 'Construye consulta SQL INSERT INTO pedidos... y la ejecuta mediante PDO.',
                    latency: 18,
                    status: 'success'
                );
                
                $steps[] = createStep(
                    nodeId: 'node-db',
                    layer: 'Base de Datos',
                    component: 'PostgreSQL DB',
                    class: 'PostgreSQL 16 Engine',
                    action: "🟢 Registro insertado con éxito. ID Pedido: {$orderId}. Commit exitoso.",
                    latency: 12,
                    status: 'success'
                );

                return [
                    'http_code' => 200,
                    'status' => '200 OK - Pedido Creado',
                    'message' => 'Pedido procesado correctamente a través de la pila secuencial del Monolito.',
                    'order_id' => $orderId,
                    'total_time_ms' => round((microtime(true) - $startTime) * 1000, 2),
                    'steps' => $steps,
                    'db_persisted' => $persisted
                ];
            }

        // ---------------------------------------------------------------------
        // 2. ARQUITECTURA EN CEBOLLA (Onion Architecture)
        // ---------------------------------------------------------------------
        case 'onion':
            $steps[] = createStep(
                nodeId: 'node-ui-adapter',
                layer: 'Capa Exterior (UI / Infrastructure Adapter)',
                component: 'WebCheckoutAdapter',
                class: 'Infrastructure\\Adapters\\Web\\CheckoutController',
                action: 'Recibe la petición HTTP exterior y la transforma en una solicitud entendible por el core.',
                latency: 5,
                status: 'success'
            );

            $steps[] = createStep(
                nodeId: 'node-app-service',
                layer: 'Capa de Aplicación (Application Services)',
                component: 'CreateOrderApplicationService',
                class: 'Application\\Services\\CreateOrderService::execute()',
                action: 'Orquesta el flujo de uso. Invoca las entidades del Core de Dominio y coordina repositorios.',
                latency: 12,
                status: 'success'
            );

            $steps[] = createStep(
                nodeId: 'node-domain-core',
                layer: 'Núcleo Central (Domain Core)',
                component: 'Order Entity & Domain Rules',
                class: 'Domain\\Model\\Order::create()',
                action: "Ejecuta lógica pura de negocio independiente de BD: Validación de stock e importe (\${$total}).",
                latency: 10,
                status: 'success',
                detail: 'Núcleo totalmente aislado sin dependencias de frameworks ni librerías externas.'
            );

            if ($failDb) {
                $steps[] = createStep(
                    nodeId: 'node-infra-db',
                    layer: 'Capa de Infraestructura (DB Adapter)',
                    component: 'PostgresOrderRepository',
                    class: 'Infrastructure\\Persistence\\PostgresOrderRepository::save()',
                    action: 'Intenta persistir en BD. La infraestructura detecta fallo de conexión PDO.',
                    latency: 25,
                    status: 'warning',
                    detail: 'Fallo de infraestructura capturado limpiamente por la interfaz del repositorio.'
                );

                $steps[] = createStep(
                    nodeId: 'node-domain-core',
                    layer: 'Núcleo Central (Domain Core)',
                    component: 'Domain Policy / Resilience',
                    class: 'Domain\\Events\\OrderCreationFailedEvent',
                    action: '🛡️ El Núcleo de Dominio no colapsa. Mantiene consistencia de entidades y emite evento de fallo controlado.',
                    latency: 5,
                    status: 'success',
                    detail: 'Demostración de Desacoplamiento: Las pruebas unitarias del Dominio pasan al 100% aunque la BD caiga.'
                );

                return [
                    'http_code' => 500,
                    'status' => '500 Handled Exception - Fallo de Infraestructura',
                    'message' => 'La base de datos cayó, pero la arquitectura Onion protegió el Core de Dominio sin colapsar el sistema.',
                    'order_id' => $orderId,
                    'total_time_ms' => round((microtime(true) - $startTime) * 1000, 2),
                    'steps' => $steps,
                    'db_persisted' => false
                ];
            } else {
                $persisted = tryPersistRealOrder($orderId, $order, $total, 'Arquitectura Cebolla');
                $steps[] = createStep(
                    nodeId: 'node-infra-db',
                    layer: 'Capa de Infraestructura (DB Adapter)',
                    component: 'PostgresOrderRepository',
                    class: 'Infrastructure\\Persistence\\PostgresOrderRepository::save()',
                    action: 'Implementación de infraestructura guarda exitosamente los datos mediante inversión de dependencia.',
                    latency: 20,
                    status: 'success'
                );

                return [
                    'http_code' => 200,
                    'status' => '200 OK - Pedido Creado (Onion Core Protegido)',
                    'message' => 'Pedido guardado. Inversión de dependencias ejecutada: Infraestructura -> Interfaces de Aplicación -> Dominio.',
                    'order_id' => $orderId,
                    'total_time_ms' => round((microtime(true) - $startTime) * 1000, 2),
                    'steps' => $steps,
                    'db_persisted' => $persisted
                ];
            }

        // ---------------------------------------------------------------------
        // 3. HEXAGONAL (Puertos y Adaptadores)
        // ---------------------------------------------------------------------
        case 'hexagonal':
            $steps[] = createStep(
                nodeId: 'node-http-client',
                layer: 'Actor Externo',
                component: 'HTTP Client / Web Browser',
                class: 'POST /api/v1/orders',
                action: 'Dispara solicitud de creación de pedido desde cliente exterior.',
                latency: 3,
                status: 'success'
            );

            $steps[] = createStep(
                nodeId: 'node-input-adapter',
                layer: 'Adaptador de Entrada (Driving Adapter)',
                component: 'RestOrderAdapter',
                class: 'Adapters\\Primary\\Http\\OrderRestController',
                action: 'Mapea la petición HTTP a un comando DTO (CreateOrderCommand).',
                latency: 6,
                status: 'success'
            );

            $steps[] = createStep(
                nodeId: 'node-input-port',
                layer: 'Puerto de Entrada (Input Port API)',
                component: 'CreateOrderInputPort (Interface)',
                class: 'Core\\Ports\\Input\\CreateOrderInputPort',
                action: 'Invoca el contrato de entrada definido por el núcleo de la aplicación.',
                latency: 4,
                status: 'success'
            );

            $steps[] = createStep(
                nodeId: 'node-core',
                layer: 'Hexágono / Core de Dominio',
                component: 'CreateOrderUseCase & OrderAggregate',
                class: 'Core\\UseCases\\CreateOrderUseCase::process()',
                action: "Ejecuta lógica pura del negocio (cálculo total \${$total}, reglas de descuento).",
                latency: 14,
                status: 'success'
            );

            $steps[] = createStep(
                nodeId: 'node-output-port',
                layer: 'Puerto de Salida (Output Port SPI)',
                component: 'OrderRepositoryOutputPort (Interface)',
                class: 'Core\\Ports\\Output\\OrderRepositoryOutputPort',
                action: 'El hexágono requiere guardar el pedido sin saber QUÉ base de datos se utilizará.',
                latency: 5,
                status: 'success'
            );

            if ($failDb) {
                $steps[] = createStep(
                    nodeId: 'node-output-adapter',
                    layer: 'Adaptador de Salida (Driven Adapter)',
                    component: 'PostgresDbAdapter',
                    class: 'Adapters\\Secondary\\Database\\PostgresOrderAdapter',
                    action: '⚠️ El adaptador intenta conectar con PostgreSQL y falla. Atrapa la excepción localmente.',
                    latency: 28,
                    status: 'error',
                    detail: 'Database Connection Exception caught in Driven Adapter.'
                );

                $steps[] = createStep(
                    nodeId: 'node-core',
                    layer: 'Hexágono / Core de Dominio',
                    component: 'Hexagon Circuit Handler',
                    class: 'Core\\Exceptions\\InfrastructureUnavailableException',
                    action: '🛡️ El puerto de salida eleva una excepción de dominio limpia. El hexágono permanece intacto y 100% testeable.',
                    latency: 6,
                    status: 'success',
                    detail: 'Demostración de Puertos y Adaptadores: Se puede sustituir PostgresAdapter por InMemoryAdapter en tests sin cambiar una sola línea del Hexágono.'
                );

                return [
                    'http_code' => 500,
                    'status' => '500 Adapter Failure - Hexágono Aislado',
                    'message' => 'Fallo en el Adaptador de Salida (Postgres). El Hexágono de negocio se mantuvo desacoplado y protegido.',
                    'order_id' => $orderId,
                    'total_time_ms' => round((microtime(true) - $startTime) * 1000, 2),
                    'steps' => $steps,
                    'db_persisted' => false
                ];
            } else {
                $persisted = tryPersistRealOrder($orderId, $order, $total, 'Hexagonal (Puertos y Adaptadores)');
                $steps[] = createStep(
                    nodeId: 'node-output-adapter',
                    layer: 'Adaptador de Salida (Driven Adapter)',
                    component: 'PostgresDbAdapter',
                    class: 'Adapters\\Secondary\\Database\\PostgresOrderAdapter',
                    action: 'El adaptador traduce el agregado de dominio en consultas SQL de PostgreSQL y las ejecuta.',
                    latency: 22,
                    status: 'success'
                );

                return [
                    'http_code' => 200,
                    'status' => '200 OK - Hexagonal Flow Complete',
                    'message' => 'Pedido procesado exitosamente: Adaptador Primario -> Puerto Entrada -> Core Hexágono -> Puerto Salida -> Adaptador Secundario.',
                    'order_id' => $orderId,
                    'total_time_ms' => round((microtime(true) - $startTime) * 1000, 2),
                    'steps' => $steps,
                    'db_persisted' => $persisted
                ];
            }

        // ---------------------------------------------------------------------
        // 4. CLEAN ARCHITECTURE (Robert C. Martin)
        // ---------------------------------------------------------------------
        case 'clean':
            $steps[] = createStep(
                nodeId: 'node-presenter-ctrl',
                layer: 'Frameworks & Drivers / Controllers',
                component: 'OrderController',
                class: 'UI\\Http\\Controllers\\OrderController',
                action: 'Entrada HTTP convertida en un RequestModel limpio para la arquitectura.',
                latency: 4,
                status: 'success'
            );

            $steps[] = createStep(
                nodeId: 'node-gateways',
                layer: 'Interface Adapters / Controllers',
                component: 'OrderControllerGateway',
                class: 'InterfaceAdapters\\Gateways\\OrderGateway',
                action: 'Adapta los datos entre el formato externo y las estructuras de los Casos de Uso.',
                latency: 8,
                status: 'success'
            );

            $steps[] = createStep(
                nodeId: 'node-use-cases',
                layer: 'Application Business Rules (Use Cases)',
                component: 'CreateOrderInteractor',
                class: 'UseCases\\CreateOrder\\CreateOrderInteractor',
                action: 'Ejecuta el caso de uso específico. Coordina las entidades del sistema sin conocer detalles técnicos.',
                latency: 16,
                status: 'success'
            );

            $steps[] = createStep(
                nodeId: 'node-entities',
                layer: 'Enterprise Business Rules (Entities)',
                component: 'Order & Customer Entities',
                class: 'Entities\\OrderEntity::calculateTotal()',
                action: "Regla empresarial fundamental: Estructura interna de datos e impuestos (\${$total}).",
                latency: 10,
                status: 'success',
                detail: 'Capa más interna. Reglas de negocio de mayor nivel de abstracción.'
            );

            if ($failDb) {
                $steps[] = createStep(
                    nodeId: 'node-db-gateway',
                    layer: 'DB Gateway / Interface Adapter',
                    component: 'PostgresDataGateway',
                    class: 'InterfaceAdapters\\Gateways\\PostgresDataGateway',
                    action: 'Fallo al ejecutar la persistencia en base de datos PostgreSQL.',
                    latency: 24,
                    status: 'error',
                    detail: 'DB Connection Exception caught in DB Gateway layer.'
                );

                $steps[] = createStep(
                    nodeId: 'node-presenter-ctrl',
                    layer: 'Presenters',
                    component: 'OrderErrorPresenter',
                    class: 'InterfaceAdapters\\Presenters\\OrderJsonPresenter',
                    action: '🛡️ El Presenter transforma el error de infraestructura en una respuesta estructurada legible sin romper las Entidades.',
                    latency: 7,
                    status: 'success',
                    detail: 'Cumplimiento estricto de la Regla de Dependencia: Las dependencias solo apuntan hacia el interior.'
                );

                return [
                    'http_code' => 500,
                    'status' => '500 Handled Exception - Clean Architecture',
                    'message' => 'Fallo de BD capturado en DB Gateway y formateado limpiamente por el Presenter.',
                    'order_id' => $orderId,
                    'total_time_ms' => round((microtime(true) - $startTime) * 1000, 2),
                    'steps' => $steps,
                    'db_persisted' => false
                ];
            } else {
                $persisted = tryPersistRealOrder($orderId, $order, $total, 'Clean Architecture');
                $steps[] = createStep(
                    nodeId: 'node-db-gateway',
                    layer: 'DB Gateway / Interface Adapter',
                    component: 'PostgresDataGateway',
                    class: 'InterfaceAdapters\\Gateways\\PostgresDataGateway',
                    action: 'Gateway de datos escribe los cambios en PostgreSQL y retorna un ResponseModel al Interactor.',
                    latency: 21,
                    status: 'success'
                );

                return [
                    'http_code' => 200,
                    'status' => '200 OK - Clean Architecture Success',
                    'message' => 'Flujo de Clean Architecture completado con éxito respetando las 4 capas concéntricas.',
                    'order_id' => $orderId,
                    'total_time_ms' => round((microtime(true) - $startTime) * 1000, 2),
                    'steps' => $steps,
                    'db_persisted' => $persisted
                ];
            }

        // ---------------------------------------------------------------------
        // 5. VERTICAL SLICE ARCHITECTURE
        // ---------------------------------------------------------------------
        case 'vertical_slice':
            $steps[] = createStep(
                nodeId: 'node-slice-http',
                layer: 'Endpoint Vertical (Feature Slice)',
                component: 'POST /features/orders/create',
                class: 'Features\\Orders\\CreateOrderEndpoint',
                action: 'Punto de entrada de la funcionalidad "CrearPedido". No hay capas horizontales globales.',
                latency: 5,
                status: 'success'
            );

            $steps[] = createStep(
                nodeId: 'node-slice-cmd',
                layer: 'Feature Command (DTO)',
                component: 'CreateOrderCommand',
                class: 'Features\\Orders\\CreateOrderCommand',
                action: 'Encapsula todos los datos requeridos para esta característica específica.',
                latency: 5,
                status: 'success'
            );

            if ($failDb) {
                $steps[] = createStep(
                    nodeId: 'node-slice-handler',
                    layer: 'Feature Handler (Lógica + SQL)',
                    component: 'CreateOrderHandler',
                    class: 'Features\\Orders\\CreateOrderHandler::handle()',
                    action: "Procesa lógica de negocio e intenta ejecutar SQL directo PDO en PostgreSQL...",
                    latency: 20,
                    status: 'error',
                    detail: 'PDOException: SQLSTATE[08006] Connection refused durante la ejecución de la consulta dentro del Handler.'
                );

                $steps[] = createStep(
                    nodeId: 'node-slice-db',
                    layer: 'PostgreSQL DB Direct',
                    component: 'PostgreSQL Database',
                    class: 'Direct PDO Access in Slice',
                    action: '🔴 Error de BD directo en el Handler. El fallo ocurre dentro de la tajada vertical.',
                    latency: 0,
                    status: 'error',
                    detail: 'En Vertical Slice, el acoplamiento a datos es directo por Feature. Fácil de leer, pero la falla en BD interrumpe la transacción del handler.'
                );

                return [
                    'http_code' => 500,
                    'status' => '500 Handler SQL Error',
                    'message' => 'Fallo directo en la tajada vertical de CrearPedido. Alta cohesión por feature.',
                    'order_id' => $orderId,
                    'total_time_ms' => round((microtime(true) - $startTime) * 1000, 2),
                    'steps' => $steps,
                    'db_persisted' => false
                ];
            } else {
                $persisted = tryPersistRealOrder($orderId, $order, $total, 'Vertical Slice Architecture');
                $steps[] = createStep(
                    nodeId: 'node-slice-handler',
                    layer: 'Feature Handler (Lógica + SQL)',
                    component: 'CreateOrderHandler',
                    class: 'Features\\Orders\\CreateOrderHandler::handle()',
                    action: "Valida, calcula total (\${$total}) y ejecuta SQL directo sin indirección de capas innecesarias.",
                    latency: 18,
                    status: 'success'
                );

                $steps[] = createStep(
                    nodeId: 'node-slice-db',
                    layer: 'PostgreSQL DB Direct',
                    component: 'PostgreSQL Database',
                    class: 'INSERT INTO pedidos...',
                    action: '🟢 Persistencia directa en base de datos dentro del mismo Slice.',
                    latency: 14,
                    status: 'success'
                );

                return [
                    'http_code' => 200,
                    'status' => '200 OK - Vertical Slice Handled',
                    'message' => 'Funcionalidad ejecutada con máxima cohesión y cero abstracciones innecesarias.',
                    'order_id' => $orderId,
                    'total_time_ms' => round((microtime(true) - $startTime) * 1000, 2),
                    'steps' => $steps,
                    'db_persisted' => $persisted
                ];
            }

        // ---------------------------------------------------------------------
        // 6. ORIENTADA A EVENTOS (EDA - Event-Driven Architecture)
        // ---------------------------------------------------------------------
        case 'event_driven':
            $steps[] = createStep(
                nodeId: 'node-eda-web',
                layer: 'Web API / Event Producer',
                component: 'OrderWebService',
                class: 'Producer\\OrderController::submitOrder()',
                action: 'Recibe la solicitud HTTP. Valida esquema básico del mensaje.',
                latency: 6,
                status: 'success'
            );

            $steps[] = createStep(
                nodeId: 'node-eda-publish',
                layer: 'Event Publisher',
                component: 'EventPublisherBus',
                class: 'Events\\EventBus::publish("OrderPlaced")',
                action: "Publica el evento inmutable OrderPlaced (ID: {$orderId}, Monto: \${$total}) en el Broker.",
                latency: 10,
                status: 'success'
            );

            $steps[] = createStep(
                nodeId: 'node-eda-broker',
                layer: 'Message Broker',
                component: 'RabbitMQ / Apache Kafka',
                class: 'Exchange: orders.events -> Queue: orders.process',
                action: '📥 Evento encolado con éxito. El Message Broker asume el control del mensaje.',
                latency: 12,
                status: 'queued',
                detail: 'Respuesta síncrona inmediata al cliente: 202 Accepted. Desacoplamiento temporal absoluto.'
            );

            if ($failDb) {
                // Trabajadores en paralelo
                $steps[] = createStep(
                    nodeId: 'node-worker-stock',
                    layer: 'Consumer Worker 1',
                    component: 'StockWorker',
                    class: 'Workers\\StockWorker::onOrderPlaced()',
                    action: '🟢 Procesa evento: Descuenta 1 unidad de stock en memoria/cache de inventario.',
                    latency: 15,
                    status: 'success'
                );

                $steps[] = createStep(
                    nodeId: 'node-worker-billing',
                    layer: 'Consumer Worker 2',
                    component: 'BillingWorker',
                    class: 'Workers\\BillingWorker::onOrderPlaced()',
                    action: '⚠️ Intenta registrar factura en PostgreSQL DB... La base de datos está caída.',
                    latency: 28,
                    status: 'warning',
                    detail: 'Fallo de BD atrapado por el Consumidor. Mensaje movido a Retry Queue (Dead Letter Queue).'
                );

                $steps[] = createStep(
                    nodeId: 'node-worker-email',
                    layer: 'Consumer Worker 3',
                    component: 'EmailNotificationWorker',
                    class: 'Workers\\EmailWorker::onOrderPlaced()',
                    action: '🟢 Procesa evento: Envía correo de confirmación al cliente independientemente del fallo de BD.',
                    latency: 22,
                    status: 'success'
                );

                $steps[] = createStep(
                    nodeId: 'node-eda-broker',
                    layer: 'Retry Mechanism & Eventual Consistency',
                    component: 'Dead Letter Queue (DLQ)',
                    class: 'RabbitMQ DLQ Retry Policy',
                    action: '🔄 Consistencia Eventual: BillingWorker reintentará el procesamiento con Backoff Exponencial cuando la BD vuelva a estar en línea.',
                    latency: 5,
                    status: 'queued',
                    detail: 'El usuario NO experimentó un error 500. El pedido está resguardado en la cola de eventos.'
                );

                return [
                    'http_code' => 202,
                    'status' => '202 Accepted - Pedido Encolado (Consistencia Eventual)',
                    'message' => 'La base de datos cayó, pero la arquitectura EDA encoló el pedido en el Broker. Los consumidores reintentarán automáticamente.',
                    'order_id' => $orderId,
                    'total_time_ms' => round((microtime(true) - $startTime) * 1000, 2),
                    'steps' => $steps,
                    'db_persisted' => false
                ];
            } else {
                $persisted = tryPersistRealOrder($orderId, $order, $total, 'Event-Driven Architecture');
                
                $steps[] = createStep(
                    nodeId: 'node-worker-stock',
                    layer: 'Consumer Worker 1',
                    component: 'StockWorker',
                    class: 'Workers\\StockWorker::onOrderPlaced()',
                    action: '🟢 Reservado 1x Laptop Gamer en servicio de stock.',
                    latency: 18,
                    status: 'success'
                );

                $steps[] = createStep(
                    nodeId: 'node-worker-billing',
                    layer: 'Consumer Worker 2',
                    component: 'BillingWorker',
                    class: 'Workers\\BillingWorker::onOrderPlaced()',
                    action: '🟢 Factura procesada y guardada en PostgreSQL DB.',
                    latency: 24,
                    status: 'success'
                );

                $steps[] = createStep(
                    nodeId: 'node-worker-email',
                    layer: 'Consumer Worker 3',
                    component: 'EmailNotificationWorker',
                    class: 'Workers\\EmailWorker::onOrderPlaced()',
                    action: '🟢 Email enviado exitosamente al cliente.',
                    latency: 19,
                    status: 'success'
                );

                return [
                    'http_code' => 202,
                    'status' => '202 Accepted - Event Processed Async',
                    'message' => 'Evento procesado asincrónicamente por los 3 consumidores en paralelo.',
                    'order_id' => $orderId,
                    'total_time_ms' => round((microtime(true) - $startTime) * 1000, 2),
                    'steps' => $steps,
                    'db_persisted' => $persisted
                ];
            }

        // ---------------------------------------------------------------------
        // 7. MICROSERVICIOS (Distributed Services)
        // ---------------------------------------------------------------------
        case 'microservices':
            $steps[] = createStep(
                nodeId: 'node-ms-gateway',
                layer: 'API Gateway',
                component: 'Kong / Spring Cloud Gateway',
                class: 'Gateway::routeRequest("/api/v1/checkout")',
                action: 'Autentica, aplica Rate Limiting y enruta la petición al Microservicio de Pedidos.',
                latency: 8,
                status: 'success'
            );

            $steps[] = createStep(
                nodeId: 'node-ms-orders',
                layer: 'Microservicio de Pedidos',
                component: 'Order-Service (Port 8081)',
                class: 'OrderService\\Controllers\\OrderController',
                action: 'Inicia transacción distribuida (Patrón Saga / Orchestrator).',
                latency: 15,
                status: 'success'
            );

            if ($failDb) {
                $steps[] = createStep(
                    nodeId: 'node-ms-payments',
                    layer: 'Microservicio de Pagos',
                    component: 'Payment-Service (Port 8082)',
                    class: 'PaymentService\\Client\\gRPC',
                    action: 'Llamada gRPC a Servicio de Pagos. Falla la conexión a la DB de Pagos.',
                    latency: 35,
                    status: 'error',
                    detail: 'Payment DB Unavailable. Circuit Breaker tripped.'
                );

                $steps[] = createStep(
                    nodeId: 'node-ms-gateway',
                    layer: 'API Gateway / Resilience4j',
                    component: 'Circuit Breaker Pattern',
                    class: 'Resilience4j::CircuitBreakerState(OPEN)',
                    action: '⚠️ Circuit Breaker ABIERTO. Se activa la respuesta degraded / fallback para evitar la cascada de fallos en el clúster.',
                    latency: 6,
                    status: 'warning',
                    detail: 'Respuesta 503 Service Unavailable / Degraded. Aislamiento de fallos entre microservicios.'
                );

                return [
                    'http_code' => 503,
                    'status' => '503 Service Unavailable - Circuit Breaker OPEN',
                    'message' => 'El microservicio de pagos/BD falló. El Circuit Breaker aisló la falla para evitar el colapso en cascada del clúster.',
                    'order_id' => $orderId,
                    'total_time_ms' => round((microtime(true) - $startTime) * 1000, 2),
                    'steps' => $steps,
                    'db_persisted' => false
                ];
            } else {
                $persisted = tryPersistRealOrder($orderId, $order, $total, 'Microservicios');

                $steps[] = createStep(
                    nodeId: 'node-ms-payments',
                    layer: 'Microservicio de Pagos',
                    component: 'Payment-Service (Port 8082)',
                    class: 'PaymentService\\gRPC::processPayment()',
                    action: "Llamada gRPC exitosa. Cobro de \${$total} procesado en DB de Pagos propia.",
                    latency: 28,
                    status: 'success'
                );

                $steps[] = createStep(
                    nodeId: 'node-ms-inventory',
                    layer: 'Microservicio de Inventario',
                    component: 'Inventory-Service (Port 8083)',
                    class: 'InventoryService\\REST::reserveItem()',
                    action: 'Llamada HTTP/REST exitosa. Stock reservado en DB de Inventario propia.',
                    latency: 22,
                    status: 'success'
                );

                return [
                    'http_code' => 200,
                    'status' => '200 OK - Microservices Orchestration Complete',
                    'message' => 'Orquestación de microservicios exitosa a través de gRPC y REST con bases de datos independientes.',
                    'order_id' => $orderId,
                    'total_time_ms' => round((microtime(true) - $startTime) * 1000, 2),
                    'steps' => $steps,
                    'db_persisted' => $persisted
                ];
            }

        default:
            return [
                'http_code' => 400,
                'status' => '400 Bad Request',
                'message' => 'Arquitectura no reconocida.',
                'steps' => []
            ];
    }
}

function createStep(string $nodeId, string $layer, string $component, string $class, string $action, int $latency, string $status, string $detail = ''): array {
    return [
        'node_id' => $nodeId,
        'layer' => $layer,
        'component' => $component,
        'class' => $class,
        'action' => $action,
        'latency_ms' => $latency,
        'status' => $status,
        'detail' => $detail,
        'timestamp' => date('H:i:s.') . sprintf('%03d', (microtime(true) - floor(microtime(true))) * 1000)
    ];
}

function tryPersistRealOrder($orderId, $order, $total, $archName) {
    global $dbConnected, $pdo;
    if (!$dbConnected || !$pdo) return false;
    try {
        $stmt = $pdo->prepare("INSERT INTO pedidos (cliente, producto, cantidad, total, arquitectura, estado) VALUES (:c, :p, :cant, :t, :a, :e)");
        $stmt->execute([
            ':c' => $order['cliente'],
            ':p' => $order['producto'],
            ':cant' => $order['cantidad'],
            ':t' => $total,
            ':a' => $archName,
            ':e' => 'Completado'
        ]);
        return true;
    } catch (Exception $e) {
        return false;
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Simulador de Arquitecturas de Software — Programación IV</title>
    <!-- Fuentes tipográficas modernas -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Fira+Code:wght@400;500;600&family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    
    <style>
        :root {
            --bg-dark: #090d16;
            --bg-card: #111827;
            --bg-card-hover: #1f2937;
            --border-color: #1e293b;
            --border-highlight: #334155;
            
            --text-main: #f8fafc;
            --text-muted: #94a3b8;
            --text-dim: #64748b;
            
            --accent-cyan: #06b6d4;
            --accent-indigo: #6366f1;
            --accent-blue: #3b82f6;
            --accent-emerald: #10b981;
            --accent-amber: #f59e0b;
            --accent-rose: #ef4444;
            
            --font-sans: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, sans-serif;
            --font-mono: 'Fira Code', monospace;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            background-color: var(--bg-dark);
            color: var(--text-main);
            font-family: var(--font-sans);
            line-height: 1.5;
            min-height: 100vh;
            padding: 20px;
            background-image: 
                radial-gradient(circle at 15% 15%, rgba(6, 182, 212, 0.05) 0%, transparent 40%),
                radial-gradient(circle at 85% 85%, rgba(99, 102, 241, 0.05) 0%, transparent 40%);
        }

        /* Contenedor Principal */
        .app-container {
            max-width: 1440px;
            margin: 0 auto;
            display: flex;
            flex-direction: column;
            gap: 20px;
        }

        /* HEADER HEADER */
        .app-header {
            background: rgba(17, 24, 39, 0.7);
            backdrop-filter: blur(12px);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            padding: 20px 28px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 16px;
        }

        .header-title-box h1 {
            font-size: 1.4rem;
            font-weight: 800;
            background: linear-gradient(135deg, #06b6d4 0%, #6366f1 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .header-title-box p {
            color: var(--text-muted);
            font-size: 0.85rem;
            margin-top: 2px;
        }

        .environment-badges {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 12px;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 600;
            border: 1px solid transparent;
        }

        .badge-docker {
            background: rgba(59, 130, 246, 0.1);
            color: #60a5fa;
            border-color: rgba(59, 130, 246, 0.2);
        }

        .badge-success {
            background: rgba(16, 185, 129, 0.1);
            color: #34d399;
            border-color: rgba(16, 185, 129, 0.2);
        }

        .badge-error {
            background: rgba(239, 68, 68, 0.1);
            color: #f87171;
            border-color: rgba(239, 68, 68, 0.2);
        }

        .status-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            display: inline-block;
        }

        .badge-success .status-dot { background-color: var(--accent-emerald); box-shadow: 0 0 8px var(--accent-emerald); }
        .badge-error .status-dot { background-color: var(--accent-rose); box-shadow: 0 0 8px var(--accent-rose); }

        /* CONTROL PANEL BAR */
        .control-panel {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            padding: 20px;
            display: grid;
            grid-template-columns: 2fr 1.5fr 1fr;
            gap: 20px;
            align-items: center;
        }

        @media (max-width: 1024px) {
            .control-panel {
                grid-template-columns: 1fr;
            }
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .form-group label {
            font-size: 0.8rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--text-muted);
        }

        .select-input, .text-input {
            background: #0d1321;
            border: 1px solid var(--border-highlight);
            color: var(--text-main);
            padding: 10px 14px;
            border-radius: 10px;
            font-family: var(--font-sans);
            font-size: 0.9rem;
            outline: none;
            transition: all 0.2s ease;
        }

        .select-input:focus, .text-input:focus {
            border-color: var(--accent-cyan);
            box-shadow: 0 0 0 3px rgba(6, 182, 212, 0.15);
        }

        /* TOGGLE SWITCH */
        .toggle-box {
            display: flex;
            align-items: center;
            gap: 12px;
            background: rgba(239, 68, 68, 0.05);
            border: 1px dashed rgba(239, 68, 68, 0.3);
            padding: 10px 16px;
            border-radius: 12px;
        }

        .switch {
            position: relative;
            display: inline-block;
            width: 44px;
            height: 24px;
            flex-shrink: 0;
        }

        .switch input { opacity: 0; width: 0; height: 0; }

        .slider {
            position: absolute;
            cursor: pointer;
            top: 0; left: 0; right: 0; bottom: 0;
            background-color: #334155;
            transition: .3s;
            border-radius: 24px;
        }

        .slider:before {
            position: absolute;
            content: "";
            height: 18px; width: 18px;
            left: 3px; bottom: 3px;
            background-color: white;
            transition: .3s;
            border-radius: 50%;
        }

        input:checked + .slider { background-color: var(--accent-rose); }
        input:checked + .slider:before { transform: translateX(20px); }

        .toggle-label {
            font-size: 0.82rem;
            font-weight: 600;
            color: #fca5a5;
            cursor: pointer;
        }

        /* BOTÓN PRINCIPAL */
        .btn-trigger {
            background: linear-gradient(135deg, var(--accent-cyan) 0%, var(--accent-indigo) 100%);
            color: white;
            border: none;
            padding: 12px 20px;
            border-radius: 12px;
            font-weight: 700;
            font-size: 0.95rem;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            box-shadow: 0 4px 14px rgba(6, 182, 212, 0.3);
            transition: all 0.2s ease;
            height: 100%;
            min-height: 44px;
        }

        .btn-trigger:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(6, 182, 212, 0.45);
        }

        .btn-trigger:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
        }

        /* WORKSPACE GRID: VISUALIZADOR + LOGS */
        .workspace-grid {
            display: grid;
            grid-template-columns: 1fr 420px;
            gap: 20px;
        }

        @media (max-width: 1200px) {
            .workspace-grid {
                grid-template-columns: 1fr;
            }
        }

        /* VISUALIZADOR DIAGRAMA CANVAS/NODOS */
        .visualizer-card {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            padding: 24px;
            display: flex;
            flex-direction: column;
            min-height: 480px;
            position: relative;
            overflow: hidden;
        }

        .card-header-sm {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 12px;
            border-bottom: 1px solid var(--border-color);
        }

        .card-header-sm h3 {
            font-size: 1rem;
            font-weight: 700;
            color: var(--text-main);
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .diagram-container {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
            padding: 20px 10px;
        }

        /* FLOW NODES GRAPH */
        .nodes-wrapper {
            display: flex;
            align-items: center;
            justify-content: space-between;
            width: 100%;
            gap: 12px;
            position: relative;
            z-index: 2;
            flex-wrap: nowrap;
        }

        @media (max-width: 768px) {
            .nodes-wrapper {
                flex-direction: column;
                gap: 24px;
            }
        }

        .arch-node {
            background: #0d1321;
            border: 2px solid var(--border-highlight);
            border-radius: 14px;
            padding: 16px 14px;
            flex: 1;
            min-width: 120px;
            max-width: 200px;
            text-align: center;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.3);
        }

        .arch-node .node-icon {
            font-size: 1.5rem;
            margin-bottom: 6px;
        }

        .arch-node .node-title {
            font-size: 0.82rem;
            font-weight: 700;
            color: var(--text-main);
            line-height: 1.2;
        }

        .arch-node .node-sub {
            font-size: 0.7rem;
            color: var(--text-muted);
            margin-top: 4px;
            font-family: var(--font-mono);
        }

        /* NODE STATES */
        .arch-node.active-success {
            border-color: var(--accent-emerald);
            background: rgba(16, 185, 129, 0.1);
            box-shadow: 0 0 24px rgba(16, 185, 129, 0.35);
            transform: scale(1.05);
        }

        .arch-node.active-error {
            border-color: var(--accent-rose);
            background: rgba(239, 68, 68, 0.15);
            box-shadow: 0 0 24px rgba(239, 68, 68, 0.45);
            transform: scale(1.05);
        }

        .arch-node.active-queued {
            border-color: var(--accent-amber);
            background: rgba(245, 158, 11, 0.12);
            box-shadow: 0 0 24px rgba(245, 158, 11, 0.35);
            transform: scale(1.05);
        }

        /* CONNECTORS BETWEEN NODES */
        .node-connector {
            flex: 0 0 30px;
            height: 2px;
            background: var(--border-highlight);
            position: relative;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .node-connector::after {
            content: '►';
            font-size: 8px;
            color: var(--text-dim);
            position: absolute;
            right: -2px;
        }

        .node-connector.active-pulse {
            background: var(--accent-cyan);
            box-shadow: 0 0 10px var(--accent-cyan);
        }

        .node-connector.active-pulse::after {
            color: var(--accent-cyan);
        }

        /* PARALLEL WORKERS CONTAINER (Para EDA) */
        .parallel-workers-group {
            display: flex;
            flex-direction: column;
            gap: 10px;
            flex: 1;
        }

        .parallel-workers-group .arch-node {
            max-width: 100%;
        }

        /* LOGS CONSOLE SIDEBAR */
        .console-card {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            display: flex;
            flex-direction: column;
            height: 520px;
            overflow: hidden;
        }

        .tab-bar {
            display: flex;
            background: #0b0f19;
            border-bottom: 1px solid var(--border-color);
        }

        .tab-btn {
            background: transparent;
            border: none;
            color: var(--text-muted);
            padding: 12px 18px;
            font-size: 0.8rem;
            font-weight: 700;
            cursor: pointer;
            border-bottom: 2px solid transparent;
            transition: all 0.2s;
        }

        .tab-btn.active {
            color: var(--accent-cyan);
            border-bottom-color: var(--accent-cyan);
            background: rgba(6, 182, 212, 0.05);
        }

        .console-body {
            flex: 1;
            background: #080c14;
            padding: 14px;
            font-family: var(--font-mono);
            font-size: 0.78rem;
            overflow-y: auto;
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .log-entry {
            background: rgba(15, 23, 42, 0.7);
            border-left: 3px solid var(--border-highlight);
            padding: 8px 10px;
            border-radius: 4px;
            animation: fadeIn 0.3s ease;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(4px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .log-entry.log-success { border-left-color: var(--accent-emerald); }
        .log-entry.log-error { border-left-color: var(--accent-rose); }
        .log-entry.log-warning { border-left-color: var(--accent-amber); }
        .log-entry.log-queued { border-left-color: var(--accent-cyan); }

        .log-header {
            display: flex;
            justify-content: space-between;
            color: var(--text-dim);
            font-size: 0.7rem;
            margin-bottom: 4px;
        }

        .log-layer { color: #38bdf8; font-weight: 600; }
        .log-class { color: #a7f3d0; margin-top: 2px; font-size: 0.72rem; }
        .log-action { color: var(--text-main); margin-top: 4px; line-height: 1.3; }
        .log-detail { color: #fca5a5; font-size: 0.7rem; margin-top: 4px; }

        /* FICHA TÉCNICA COMPARATIVA */
        .tech-sheet-card {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            padding: 24px;
        }

        .sheet-title-box {
            margin-bottom: 18px;
        }

        .sheet-title-box h3 {
            font-size: 1.1rem;
            font-weight: 800;
            color: var(--text-main);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .sheet-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 16px;
        }

        .info-card {
            background: #0d1321;
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 16px;
        }

        .info-card .info-title {
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--text-muted);
            margin-bottom: 6px;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .info-card .info-value {
            font-size: 0.9rem;
            color: var(--text-main);
            font-weight: 500;
            line-height: 1.4;
        }

        .complexity-meter {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 0.78rem;
            font-weight: 700;
        }

        .comp-bajo { background: rgba(16, 185, 129, 0.2); color: #34d399; }
        .comp-medio { background: rgba(59, 130, 246, 0.2); color: #60a5fa; }
        .comp-alto { background: rgba(245, 158, 11, 0.2); color: #fbbf24; }
        .comp-muy-alto { background: rgba(239, 68, 68, 0.2); color: #f87171; }

        /* STATUS BANNER */
        .status-banner {
            padding: 12px 16px;
            border-radius: 10px;
            font-size: 0.85rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-top: 12px;
        }

        .banner-success { background: rgba(16, 185, 129, 0.15); color: #34d399; border: 1px solid rgba(16, 185, 129, 0.3); }
        .banner-error { background: rgba(239, 68, 68, 0.15); color: #f87171; border: 1px solid rgba(239, 68, 68, 0.3); }
        .banner-queued { background: rgba(6, 182, 212, 0.15); color: #38bdf8; border: 1px solid rgba(6, 182, 212, 0.3); }
    </style>
</head>
<body>

<div class="app-container">
    
    <!-- HEADER -->
    <header class="app-header">
        <div class="header-title-box">
            <h1>🏛️ Programación IV — Simulador de Arquitecturas</h1>
            <p>Laboratorio interactivo de patrones arquitectónicos en PHP 8.4 & Docker PostgreSQL</p>
        </div>
        <div class="environment-badges">
            <span class="badge badge-docker">🐳 Apache + PHP 8.4 (Port 8088)</span>
            <?php if ($dbConnected): ?>
                <span class="badge badge-success"><span class="status-dot"></span> PostgreSQL 16 Online</span>
            <?php else: ?>
                <span class="badge badge-error"><span class="status-dot"></span> DB Offline</span>
            <?php endif; ?>
        </div>
    </header>

    <!-- BARRA DE CONTROL DE LA SIMULACIÓN -->
    <section class="control-panel">
        <div class="form-group">
            <label for="archSelect">1. Seleccionar Arquitectura de Software</label>
            <select id="archSelect" class="select-input">
                <option value="monolithic">1. Monolítica en Capas (N-Tier tradicional)</option>
                <option value="onion">2. Cebolla (Onion Architecture)</option>
                <option value="hexagonal">3. Hexagonal (Puertos y Adaptadores)</option>
                <option value="clean">4. Limpia (Clean Architecture)</option>
                <option value="vertical_slice">5. Vertical Slice Architecture</option>
                <option value="event_driven">6. Orientada a Eventos (EDA)</option>
                <option value="microservices">7. Microservicios</option>
            </select>
        </div>

        <div class="form-group">
            <label>2. Prueba de Desacoplamiento & Fallos</label>
            <div class="toggle-box">
                <label class="switch">
                    <input type="checkbox" id="failDbToggle">
                    <span class="slider"></span>
                </label>
                <label for="failDbToggle" class="toggle-label">Simular Caída de Base de Datos</label>
            </div>
        </div>

        <button id="btnRunSimulation" class="btn-trigger">
            <span>🚀 Enviar Request (Crear Pedido)</span>
        </button>
    </section>

    <!-- ESPACIO DE TRABAJO: VISUALIZADOR + CONSOLA DE LOGS -->
    <main class="workspace-grid">
        
        <!-- VISUALIZADOR CANVAS / DIAGRAMA DE NODOS -->
        <div class="visualizer-card">
            <div class="card-header-sm">
                <h3 id="visualizerTitle">🗺️ Diagrama de Componentes de Arquitectura</h3>
                <span id="orderPayloadBadge" style="font-family: var(--font-mono); font-size: 0.75rem; color: var(--text-muted);">Payload: Laptop Gamer ($1,850.00)</span>
            </div>

            <div class="diagram-container">
                <div id="nodesWrapper" class="nodes-wrapper">
                    <!-- Los nodos de la arquitectura seleccionada se renderizan dinámicamente con JavaScript -->
                </div>
            </div>

            <div id="statusBanner" class="status-banner" style="display: none;">
                <span id="bannerMessage">Esperando ejecución...</span>
                <span id="bannerTime" style="font-family: var(--font-mono); font-size: 0.75rem;">0 ms</span>
            </div>
        </div>

        <!-- CONSOLA LATERAL DE LOGS Y PAYLOAD -->
        <div class="console-card">
            <div class="tab-bar">
                <button class="tab-btn active" id="tabLogsBtn">📜 Console Log de Ejecución</button>
                <button class="tab-btn" id="tabJsonBtn">📦 Inspector JSON HTTP</button>
            </div>
            
            <div id="consoleLogsBody" class="console-body">
                <div style="color: var(--text-dim); text-align: center; padding-top: 40px;">
                    Selecciona una arquitectura y presiona<br><strong style="color: var(--accent-cyan);">"🚀 Enviar Request"</strong><br>para iniciar el rastreo de capas.
                </div>
            </div>

            <div id="consoleJsonBody" class="console-body" style="display: none;">
                <pre id="jsonInspector" style="color: #a7f3d0; white-space: pre-wrap; font-size: 0.72rem;">// El objeto JSON de petición y respuesta aparecerá aquí...</pre>
            </div>
        </div>

    </main>

    <!-- FICHA TÉCNICA COMPARATIVA EDUCATIVA -->
    <section class="tech-sheet-card">
        <div class="sheet-title-box">
            <h3 id="sheetTitle">📋 Ficha Técnica Comparativa — Monolítica en Capas</h3>
        </div>

        <div class="sheet-grid">
            <div class="info-card">
                <div class="info-title">📊 Nivel de Complejidad</div>
                <div class="info-value" id="sheetComplexity"><span class="complexity-meter comp-bajo">Bajo / Medio</span></div>
            </div>

            <div class="info-card">
                <div class="info-title">🎯 Mejor Escenario de Aplicación</div>
                <div class="info-value" id="sheetBestUse">Prototipos, MVPs y aplicaciones pequeñas/medianas con dominios sencillos.</div>
            </div>

            <div class="info-card">
                <div class="info-title">⚠️ Cuándo NO Usarla (Riesgos)</div>
                <div class="info-value" id="sheetAvoid">Sistemas de alta complejidad donde las reglas de negocio cambian frecuentemente.</div>
            </div>

            <div class="info-card">
                <div class="info-title">🔑 Principio Clave / Filosofía</div>
                <div class="info-value" id="sheetPrinciple">Separación de responsabilidades en capas strictly de arriba hacia abajo.</div>
            </div>

            <div class="info-card">
                <div class="info-title">🧩 Patrones Asociados</div>
                <div class="info-value" id="sheetPatterns">DAO, DTO, Active Record, Controller-Service.</div>
            </div>
        </div>
    </section>

</div>

<!-- SCRIPT FRONTEND INTERACTIVO -->
<script>
// METADATA DE LAS 7 ARQUITECTURAS
const ARCHITECTURES = {
    monolithic: {
        title: "Monolítica en Capas (N-Tier tradicional)",
        complexity: "Bajo / Medio",
        complexityClass: "comp-bajo",
        bestUse: "Aplicaciones pequeñas o medianas, proyectos con equipos reducidos o MVPs rápidos.",
        avoid: "Sistemas enterprise masivos o dominios con reglas de negocio altamente complejas.",
        principle: "Separación horizontal en capas de arriba hacia abajo (UI -> Controller -> Service -> DAO).",
        patterns: "DAO (Data Access Object), DTO, Service Layer, Active Record.",
        nodes: [
            { id: "node-ui", title: "UI / Request", sub: "HTTP POST /pedidos", icon: "🌐" },
            { id: "node-controller", title: "Controlador", sub: "OrderController.php", icon: "🎮" },
            { id: "node-service", title: "Servicio Negocio", sub: "OrderService.php", icon: "⚙️" },
            { id: "node-dao", title: "DAO / Acceso Datos", sub: "OrderDAO.php", icon: "📦" },
            { id: "node-db", title: "PostgreSQL DB", sub: "app_db:5432", icon: "🛢️" }
        ]
    },
    onion: {
        title: "Cebolla (Onion Architecture)",
        complexity: "Alto",
        complexityClass: "comp-alto",
        bestUse: "Sistemas empresariales con reglas de negocio complejas que deben aislarse de la infraestructura.",
        avoid: "Aplicaciones CRUD simples donde no existe lógica de dominio significativa.",
        principle: "Inversión de Dependencias. El centro (Core de Dominio) es independiente de bases de datos y UI.",
        patterns: "Domain Entities, Application Services, Repository Pattern, Dependency Injection.",
        nodes: [
            { id: "node-ui-adapter", title: "UI / Adaptador", sub: "CheckoutController", icon: "🔌" },
            { id: "node-app-service", title: "Servicio Aplicación", sub: "CreateOrderAppService", icon: "🚀" },
            { id: "node-domain-core", title: "Dominio / Core", sub: "Order Entity & Rules", icon: "🧠" },
            { id: "node-infra-db", title: "Infraestructura DB", sub: "PostgresOrderRepo", icon: "🛢️" }
        ]
    },
    hexagonal: {
        title: "Hexagonal (Puertos y Adaptadores)",
        complexity: "Alto",
        complexityClass: "comp-alto",
        bestUse: "Sistemas que interactúan con múltiples clientes (REST, CLI, gRPC) y múltiples orígenes de datos.",
        avoid: "Proyectos simples sin necesidad de intercambiar adaptadores ni realizar pruebas aisladas.",
        principle: "El núcleo de aplicación se comunica con el mundo exterior únicamente mediante Puertos e Interfaces.",
        patterns: "Driving/Driven Adapters, Ports (Input/Output APIs), Dependency Inversion.",
        nodes: [
            { id: "node-http-client", title: "Cliente HTTP", sub: "Browser / REST Client", icon: "💻" },
            { id: "node-input-adapter", title: "Adaptador Input", sub: "RestOrderAdapter", icon: "🔌" },
            { id: "node-input-port", title: "Puerto Entrada", sub: "CreateOrderInputPort", icon: "🚪" },
            { id: "node-core", title: "Caso Uso / Núcleo", sub: "CreateOrderUseCase", icon: "💎" },
            { id: "node-output-port", title: "Puerto Repo (SPI)", sub: "OrderRepositoryPort", icon: "🔑" },
            { id: "node-output-adapter", title: "Adaptador PostgreSQL", sub: "PostgresDbAdapter", icon: "🛢️" }
        ]
    },
    clean: {
        title: "Limpia (Clean Architecture)",
        complexity: "Muy Alto",
        complexityClass: "comp-muy-alto",
        bestUse: "Aplicaciones enterprise de largo plazo donde la independencia de frameworks y testabilidad son prioritarias.",
        avoid: "Proyectos pequeños o de desarrollo rápido por el alto nivel de indirección y código repetitivo.",
        principle: "Regla de la Dependencia: Las dependencias solo pueden apuntar hacia adentro, hacia las Entidades.",
        patterns: "Use Case Interactors, Presenters, Gateways, Entities, Response Models.",
        nodes: [
            { id: "node-presenter-ctrl", title: "Presentador / Control", sub: "OrderController & Presenter", icon: "🎨" },
            { id: "node-gateways", title: "Controladores / Gateways", sub: "OrderGateway", icon: "🌉" },
            { id: "node-use-cases", title: "Casos de Uso", sub: "CreateOrderInteractor", icon: "📜" },
            { id: "node-entities", title: "Entidades Core", sub: "Order & Customer Entities", icon: "🏛️" },
            { id: "node-db-gateway", title: "DB Gateway", sub: "PostgresDataGateway", icon: "🛢️" }
        ]
    },
    vertical_slice: {
        title: "Vertical Slice Architecture",
        complexity: "Medio",
        complexityClass: "comp-medio",
        bestUse: "Sistemas orientados a Features donde cada funcionalidad evoluciona e incrementa de forma independiente.",
        avoid: "Sistemas con reglas de negocio masivas altamente compartidas entre docenas de funcionalidades.",
        principle: "Alta cohesión por Feature en lugar de acoplamiento horizontal por Capas.",
        patterns: "CQRS, MediatR / Command Handlers, Direct Data Access por Feature.",
        nodes: [
            { id: "node-slice-http", title: "HTTP Post: CrearPedido", sub: "Features/Orders/Create", icon: "🎯" },
            { id: "node-slice-cmd", title: "CreateOrderCommand", sub: "Command DTO", icon: "✉️" },
            { id: "node-slice-handler", title: "CreateOrderHandler", sub: "Lógica + SQL directo", icon: "⚡" },
            { id: "node-slice-db", title: "PostgreSQL DB", sub: "SQL Direct Access", icon: "🛢️" }
        ]
    },
    event_driven: {
        title: "Orientada a Eventos (EDA - Event-Driven Architecture)",
        complexity: "Muy Alto",
        complexityClass: "comp-muy-alto",
        bestUse: "Sistemas distribuidos asíncronos de gran escala, procesamiento background y alta concurrencia.",
        avoid: "Operaciones síncronas que requieren confirmación inmediata estricta e instantánea.",
        principle: "Desacoplamiento temporal y espacial mediante eventos inmutables y consistencia eventual.",
        patterns: "Event Broker, Publish-Subscribe, Dead Letter Queue, Workers Asíncronos.",
        isEda: true,
        nodes: [
            { id: "node-eda-web", title: "Web Service", sub: "OrderProducerController", icon: "🌐" },
            { id: "node-eda-publish", title: "Publicar Evento", sub: "OrderPlacedEvent", icon: "📢" },
            { id: "node-eda-broker", title: "Message Broker", sub: "RabbitMQ / Kafka Queue", icon: "📬" },
            { 
                isParallelGroup: true,
                workers: [
                    { id: "node-worker-stock", title: "Worker 1: Stock", sub: "Descuento Inventario", icon: "📦" },
                    { id: "node-worker-billing", title: "Worker 2: Facturación", sub: "Persistencia DB Factura", icon: "🧾" },
                    { id: "node-worker-email", title: "Worker 3: Email", sub: "Notificación Cliente", icon: "📧" }
                ]
            }
        ]
    },
    microservices: {
        title: "Microservicios (Arquitectura Distribuida)",
        complexity: "Muy Alto",
        complexityClass: "comp-muy-alto",
        bestUse: "Grandes empresas con múltiples equipos independientes y servicios con necesidades de escalado autónomo.",
        avoid: "Equipos pequeños o startups iniciales (introduce latencia de red y complejidad operativa).",
        principle: "Bases de datos independientes por servicio, comunicación gRPC/REST y tolerancia a fallos.",
        patterns: "API Gateway, Circuit Breaker, Saga Pattern, Database-per-service.",
        nodes: [
            { id: "node-ms-gateway", title: "API Gateway", sub: "Kong / Spring Gateway", icon: "🚪" },
            { id: "node-ms-orders", title: "Servicio Pedidos + DB", sub: "Order Microservice", icon: "🛒" },
            { id: "node-ms-payments", title: "Servicio Pagos + DB", sub: "Payment Microservice (gRPC)", icon: "💳" },
            { id: "node-ms-inventory", title: "Servicio Inventario", sub: "Inventory Microservice", icon: "🏬" }
        ]
    }
};

// ELEMENTOS DEL DOM
const archSelect = document.getElementById('archSelect');
const failDbToggle = document.getElementById('failDbToggle');
const btnRunSimulation = document.getElementById('btnRunSimulation');
const nodesWrapper = document.getElementById('nodesWrapper');
const consoleLogsBody = document.getElementById('consoleLogsBody');
const consoleJsonBody = document.getElementById('consoleJsonBody');
const jsonInspector = document.getElementById('jsonInspector');
const statusBanner = document.getElementById('statusBanner');
const bannerMessage = document.getElementById('bannerMessage');
const bannerTime = document.getElementById('bannerTime');
const tabLogsBtn = document.getElementById('tabLogsBtn');
const tabJsonBtn = document.getElementById('tabJsonBtn');

// RENDERIZAR DIAGRAMA DE NODOS
function renderDiagram(archKey) {
    const arch = ARCHITECTURES[archKey];
    if (!arch) return;

    nodesWrapper.innerHTML = '';
    
    arch.nodes.forEach((item, index) => {
        if (item.isParallelGroup) {
            // Renderizar grupo de trabajadores en paralelo (para EDA)
            const groupDiv = document.createElement('div');
            groupDiv.className = 'parallel-workers-group';
            
            item.workers.forEach(w => {
                const wNode = document.createElement('div');
                wNode.className = 'arch-node';
                wNode.id = w.id;
                wNode.innerHTML = `
                    <div class="node-icon">${w.icon}</div>
                    <div class="node-title">${w.title}</div>
                    <div class="node-sub">${w.sub}</div>
                `;
                groupDiv.appendChild(wNode);
            });
            
            nodesWrapper.appendChild(groupDiv);
        } else {
            // Renderizar nodo individual
            const nodeDiv = document.createElement('div');
            nodeDiv.className = 'arch-node';
            nodeDiv.id = item.id;
            nodeDiv.innerHTML = `
                <div class="node-icon">${item.icon}</div>
                <div class="node-title">${item.title}</div>
                <div class="node-sub">${item.sub}</div>
            `;
            nodesWrapper.appendChild(nodeDiv);
        }

        // Agregar conector si no es el último elemento
        if (index < arch.nodes.length - 1) {
            const connector = document.createElement('div');
            connector.className = 'node-connector';
            connector.id = `connector-${index}`;
            nodesWrapper.appendChild(connector);
        }
    });

    // Actualizar Ficha Técnica
    document.getElementById('sheetTitle').textContent = `📋 Ficha Técnica Comparativa — ${arch.title}`;
    document.getElementById('sheetComplexity').innerHTML = `<span class="complexity-meter ${arch.complexityClass}">${arch.complexity}</span>`;
    document.getElementById('sheetBestUse').textContent = arch.bestUse;
    document.getElementById('sheetAvoid').textContent = arch.avoid;
    document.getElementById('sheetPrinciple').textContent = arch.principle;
    document.getElementById('sheetPatterns').textContent = arch.patterns;

    // Resetear banner
    statusBanner.style.display = 'none';
}

// MANEJO DE PESTAÑAS CONSOLA / JSON
tabLogsBtn.addEventListener('click', () => {
    tabLogsBtn.classList.add('active');
    tabJsonBtn.classList.remove('active');
    consoleLogsBody.style.display = 'flex';
    consoleJsonBody.style.display = 'none';
});

tabJsonBtn.addEventListener('click', () => {
    tabJsonBtn.classList.add('active');
    tabLogsBtn.classList.remove('active');
    consoleJsonBody.style.display = 'block';
    consoleLogsBody.style.display = 'none';
});

// EVENTO DE CAMBIO DE ARQUITECTURA
archSelect.addEventListener('change', (e) => {
    renderDiagram(e.target.value);
    consoleLogsBody.innerHTML = `<div style="color: var(--text-dim); text-align: center; padding-top: 40px;">Diagrama actualizado a <strong>${ARCHITECTURES[e.target.value].title}</strong>.<br>Presiona "🚀 Enviar Request" para simular.</div>`;
});

// EJECUTAR SIMULACIÓN (FETCH AL BACKEND PHP)
btnRunSimulation.addEventListener('click', async () => {
    const archKey = archSelect.value;
    const failDb = failDbToggle.checked;
    
    // UI state
    btnRunSimulation.disabled = true;
    btnRunSimulation.innerHTML = `<span>⏳ Procesando capas...</span>`;
    consoleLogsBody.innerHTML = '';
    statusBanner.style.display = 'none';
    
    // Resetear nodos
    document.querySelectorAll('.arch-node').forEach(n => {
        n.className = 'arch-node';
    });
    document.querySelectorAll('.node-connector').forEach(c => {
        c.classList.remove('active-pulse');
    });

    const payload = {
        architecture: archKey,
        simulate_db_failure: failDb,
        order_data: {
            cliente: "Juan Pérez",
            producto: "Laptop Gamer RTX 4080",
            cantidad: 1,
            precio_unitario: 1850.00
        }
    };

    try {
        const response = await fetch('index.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });

        const data = await response.json();
        
        // Renderizar inspector JSON
        jsonInspector.textContent = JSON.stringify({ request: payload, response: data }, null, 2);

        // ANIMACIÓN PASO A PASO
        const steps = data.steps || [];
        for (let i = 0; i < steps.length; i++) {
            const step = steps[i];
            
            // Pausa simulada de latencia
            await new Promise(r => setTimeout(r, Math.max(step.latency_ms * 25, 300)));

            // Activar conector
            const connector = document.getElementById(`connector-${i}`);
            if (connector) connector.classList.add('active-pulse');

            // Iluminar nodo activo
            const targetNode = document.getElementById(step.node_id);
            if (targetNode) {
                targetNode.classList.remove('active-success', 'active-error', 'active-queued');
                if (step.status === 'success') targetNode.classList.add('active-success');
                else if (step.status === 'error') targetNode.classList.add('active-error');
                else if (step.status === 'queued') targetNode.classList.add('active-queued');
                else if (step.status === 'warning') targetNode.classList.add('active-error');
            }

            // Agregar línea a la consola de logs
            const logItem = document.createElement('div');
            logItem.className = `log-entry log-${step.status}`;
            logItem.innerHTML = `
                <div class="log-header">
                    <span class="log-layer">[Paso ${i+1}] ${step.layer}</span>
                    <span>${step.timestamp} (${step.latency_ms}ms)</span>
                </div>
                <div class="log-class">⚡ ${step.class}</div>
                <div class="log-action">${step.action}</div>
                ${step.detail ? `<div class="log-detail">ℹ️ ${step.detail}</div>` : ''}
            `;
            consoleLogsBody.appendChild(logItem);
            consoleLogsBody.scrollTop = consoleLogsBody.scrollHeight;
        }

        // MOSTRAR BANNER FINAL DE ESTADO
        statusBanner.style.display = 'flex';
        statusBanner.className = 'status-banner ' + (
            data.http_code === 200 ? 'banner-success' : 
            data.http_code === 202 ? 'banner-queued' : 'banner-error'
        );
        bannerMessage.textContent = `${data.status}: ${data.message}`;
        bannerTime.textContent = `Latencia Total: ${data.total_time_ms} ms | DB Persist: ${data.db_persisted ? 'SÍ' : 'NO'}`;

    } catch (err) {
        consoleLogsBody.innerHTML += `<div class="log-entry log-error">Error al conectar con la API de PHP: ${err.message}</div>`;
    } finally {
        btnRunSimulation.disabled = false;
        btnRunSimulation.innerHTML = `<span>🚀 Enviar Request (Crear Pedido)</span>`;
    }
});

// INICIALIZAR DIAGRAMA AL CARGAR LA PÁGINA
renderDiagram('monolithic');
</script>

</body>
</html>