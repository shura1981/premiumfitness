---
name: php85-coding
description: Toolkit and guidelines for writing and generating modern PHP 8.5 code. Use when asked to write PHP code, generate classes, create scripts, or implement new PHP 8.5 syntax like the pipe operator, clone with syntax, array_first, array_last, or asymmetric visibility.
---

# PHP 8.5 Coding Guidelines

This skill provides guidelines and workflows for writing and structuring new PHP code to leverage the features, syntax, and standards introduced in PHP 8.5.

## When to Use This Skill
- Writing new PHP code, generating classes, or creating features from scratch.
- Answering questions about how to code effectively in PHP 8.5.
- Implementing new PHP 8.5 syntax like the pipe operator (`|>`) or `clone with`.
- Generating OOP structures using specific PHP 8.5 details (`final` properties, asymmetric visibility).

## Key Features & Syntax Examples

### 1. Pipe Operator (`|>`)
Avoid deeply nested function calls by chaining results sequentially.
**Before:**
```php
$result = strtolower(trim(strip_tags($input)));
```
**PHP 8.5:**
```php
$result = $input |> strip_tags(...) |> trim(...) |> strtolower(...);
```

### 2. `clone with` Syntax
Avoid boilerplate code when cloning objects and changing specific properties.
**PHP 8.5:**
```php
$newObject = clone($this, ['propertyName' => $newValue]);
```

### 3. Native Array Functions
Use the new native functions to get boundaries of an array easily.
**PHP 8.5:**
```php
$first = array_first($array);
$last = array_last($array);
```

### 4. Object-Oriented Enhancements
- **Asymmetric Visibility for Static Properties:** You can now define `public private(set) static string $prop;`.
- **Final Property Promotion:** Support for `final` keyword in constructor property promotion.
- **Attributes on Properties:** `#[Override]` can now be applied directly to class properties.
- **Closures in Constants:** Anonymous functions (closures) explicitly marked as `static` can be used inside constant expressions.

### 5. Validation and API Enhancements
- **URI API:** Use `Uri\Rfc3986\Uri` for robust URI parsing and validation instead of manual regex/`parse_url`.
- **Filters:** Use `FILTER_THROW_ON_FAILURE` flag with `filter_var()` to throw an exception instead of returning false on error.
- **`#[DelayedTargetValidation]`:** Defer validation of certain attributes to runtime via reflection.
- **`#[NoDiscard]`:** Apply this attribute to functions where the return value must be checked/used. Suppress warnings by casting to `(void)`.

## Deprecations to Address (PHP 8.5)

When modernizing, ensure the following deprecations and removals are handled:
1. **Invalid Casts:** Replace `(boolean)`, `(integer)`, and `(double)` strictly with `(bool)`, `(int)`, and `(float)`.
2. **Backticks:** Replace backticks for shell commands (`` `command` ``) with clear `shell_exec('command')` calls.
3. **Constant Redeclaration:** Ensure code does not attempt to redeclare constants that are already defined.
4. **Aliases and Redundant Functions:**
   - Use `mysqli_stmt_execute()` instead of `mysqli_execute()`.
   - Use `stream_set_timeout()` instead of `socket_set_timeout()`.
   - Remove obsolete cleanup functions that no longer do anything since PHP 8.0: `curl_close()`, `curl_share_close()`, `xml_parser_free()`.
5. **Output Buffering:** Avoid emitting direct output from custom output buffer handlers.

## Step-by-Step Coding Workflow

1. **Understand Request:** Review what the user wants to accomplish and decide which PHP 8.5 structures apply.
2. **Apply Syntax:**
   - Use the pipe operator (`|>`) when creating sequences of function calls.
   - Use `clone with` to simplify object cloning and property mutation.
   - Prefer `array_first()`/`array_last()` over manual boundary checks.
3. **Optimize Arrays & OOP:** Design classes leveraging final properties and asymmetric visibility if it improves encapsulation.
4. **Linting Check:** When delivering code, ensure no deprecated syntaxes (like `(boolean)` or backticks) are present. Recommend running the static analyzer (e.g., `composer run lint`).