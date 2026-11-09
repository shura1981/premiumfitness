---
name: clean-code-php
description: Toolkit for generating clean, maintainable, and modern PHP code. Use when asked to write or refactor PHP, split large methods, apply early returns, nullsafe operators, match expressions, logical assignment, improve naming, or add strict types with PHPStan/Psalm-compatible PHPDoc. Covers general code quality patterns for Domain-Driven Design (DDD), controllers, services, and repositories.
---

# Clean Code for PHP (Modern PHP 8+)

Guía para producir código claro, consistente y fácil de mantener en PHP moderno (versión 8.0 y superior). 

## When to Use This Skill
- Crear o refactorizar código PHP.
- Reducir complejidad en métodos grandes o con anidación profunda (nesting).
- Mejorar legibilidad, nombrado y estructura de clases/funciones.
- Aplicar tipado estricto nativo y anotaciones en PHPDoc para PHPStan/Psalm.

## Principios Base
- **Declaración estricta**: Siempre debes usar `declare(strict_types=1);` en la primera línea de cada archivo.
- **Claridad primero**: Prioriza que el código se lea de forma natural y secuencial.
- **Responsabilidad única**: Una clase/método, una tarea.
- **Pequeñas unidades**: Divide métodos orquestadores grandes en métodos privados enfocados (helpers).
- **Inmutabilidad (cuando aplique)**: Usa `readonly` para clases o propiedades (PHP 8.1/8.2).

## Patrones Esenciales

### Early Return (Guard Clauses)
- Retorna excepciones o resultados negativos rápidamente para reducir niveles de indentación.
```php
public function handle(?User $user): ?Response 
{
    if ($user === null) {
        return null;
    }
    
    if (! $user->isActive()) {
        return null;
    }
    
    return $this->buildResponse($user);
}
```

### Extracción de Métodos Auxiliares
- Un método público orquestador debe llamar a métodos privados especializados para que el flujo principal se lea como un índice.
```php
public function processOrder(OrderInput $input): OrderResult 
{
    $this->validateInput($input);
    $items = $this->normalizeItems($input->items);
    $totals = $this->calculateTotals($items);
    
    return clone($this->resultTemplate, ['items' => $items, 'totals' => $totals]); // PHP 8.5
}
```

### Nullsafe Operator (`?->`)
- Úsalo para encadenar llamadas a métodos en objetos que podrían ser `null` en lugar de usar comprobaciones anidadas de `if ($obj !== null)`.
```php
$city = $profile?->getAddress()?->getCity();
```

### Null Coalescing Operator (`??`)
- Úsalo para definir valores por defecto cuando algo pueda ser `null`.
```php
$retries = $config['retries'] ?? 3;
```

### Null Coalescing Assignment (`??=`)
- Asignación directa si la variable o clave actualmente es nula.
```php
$options['timeout'] ??= 5000;
```

### Alternativas a `switch`: Expresión `match` (PHP 8.0)
- Evita por completo la sentencia `switch`. El bloque `match` es estricto (comparación `===`), no requiere `break` y devuelve un valor directamente.
```php
$label = match($status) {
    Status::PENDING => 'Pendiente',
    Status::PAID => 'Pagado',
    Status::FAILED => 'Fallido',
    default => 'Desconocido',
};
```

### Alternativas a `for` o `foreach` puros
- Utiliza `array_map`, `array_filter`, `array_reduce` para transformaciones de colecciones sin estado lateral, o bien las funciones `array_first()` y `array_last()` de PHP 8.5 si necesitas los límites.
- Prefiere `foreach` por encima de `for` cuando iterar arreglos tradicionales y necesites control de flujo con `break` o `continue`.
```php
$ids = array_map(fn($item) => $item->id, $items);
$total = array_reduce($items, fn($sum, $item) => $sum + $item->amount, 0);
```

### Magic Values: Usa Enums (PHP 8.1+) y Constantes
- Evita los strings mágicos sueltos. Para dominios cerrados de opciones, utiliza Enums nativos.
```php
enum OrderStatus: string {
    case PENDING = 'pending';
    case PAID = 'paid';
    case FAILED = 'failed';
}

// Uso:
$currentStatus = OrderStatus::PENDING;
```

## Tipado Estricto en PHP

### Reglas Prácticas
- Asegúrate de definir siempre tipos para **todos** los argumentos de métodos, valores de retorno y propiedades de clase.
- Usa tipos de unión (Union types: `int|float`) e intersección (`ClassA&ClassB`) si son necesarios.
- Usa `mixed` en lugar de omitir un tipo si realmente no conoces la entrada, para obligar al chequeo manual de tipo. No uses el tipo implícito.
```php
public function setId(int|string $id): void;
public function process(mixed $payload): array;
```

### PHPDoc para PHPStan y Psalm y genéricos (`@template`)
- Si tu arreglo contiene objetos, el tipado de array nativo `array` no es suficiente. Debes usar notación de genéricos de PHPDoc (`array<User>`, `Collection<int, User>`).
```php
/**
 * @param array<int, Order> $orders
 * @return array<string, OrderSummary>
 */
public function summarizeOrders(array $orders): array 
{ ... }
```

## Nombrado y Semántica
- **Variables**: Nombres descriptivos (camelCase), evita letras sueltas (excepto `$i` en un ciclo corto).
- **Manejo de booleanos**: Prefixar con `is`, `has`, `can`, `should` (ejemplo: `isActive`, `hasPermissions`).
- **Clases y Funciones**: Clases son sustantivos (`PascalCase`). Métodos son verbos de acción (`camelCase` como `fetchUser`, `buildPayload`).

## Manejo de Errores (Excepciones)
- Lanza excepciones expresivas usando clases propias del dominio (ej. `UserNotFoundException` en lugar de una genérica `Exception`).
- Captura las excepciones de base de datos o APIs externas en los límites de tu sistema (repositorios/infraestructura) y devuélvelas convertidas en Excepciones de tu Dominio.
- No uses `try/catch` para la lógica de control de flujo estándar. Para eso, usa retornos temprano (early returns) si falla una simple validación.

## Workflows

### 1) Refactor de Método Grande (Spaghetti)
1. Identifica diferentes áreas de responsabilidad dentro del método.
2. Inyecta dependencias para retirar lógicas externas.
3. Extrae bloques de código a métodos privados (helpers) usando sustantivos/verbos muy declarativos.
4. Aplica `early return` e invierte condicionales (ej. cambiar `if ($ok) { ... }` por `if (!$ok) { return; } ...`).
5. Reemplaza validaciones de nulos por el nullsafe operator (`?->`) y la asignación `??=`.

### 2) Fortalecimiento de Tipado
1. Asegura que el archivo tenga `declare(strict_types=1);` al tope.
2. Revisa que todas las propiedades de clase tengan tipos.
3. Actualiza cada firma de método con argumentos tipeados y retornos obligatorios.
4. Si retorna arreglos, agrega la etiqueta PHPDoc `/** @return array<Tipo> */`.
5. Si un método no retorna nada, asegúrate que se defina `void`.