---
description: 'Instrucciones para la creación de Agent Skills. Usar cuando el usuario pida crear o modificar un skill de IA.'
applyTo: '**/.agents/skills/**/SKILL.md'
---

# Directrices para Archivos de Agent Skills

Instrucciones para crear Agent Skills efectivos y portables que dotan a asistentes (como Copilot o Antigravity) con flujos de trabajo especializados y recursos empaquetados.

## ¿Qué son los Agent Skills?
Son carpetas independientes con instrucciones y recursos que enseñan al agente habilidades especializadas.
Características principales:
- **Portabilidad**: Funcionan en distintos editores y agentes.
- **Carga progresiva**: Solo se cargan si son relevantes para la petición del usuario.
- **Recursos empaquetados**: Pueden incluir scripts, plantillas, datos de referencia.
- **Por demanda**: Se activan automáticamente según la relevancia del prompt.

## Estructura de Directorio

Los skills se guardan en:
- Proyecto: `.agents/skills/<nombre-skill>/`

Las skills de este repositorio son una copia de las de `backend-radical-nutrition`. Si se mejora una, conviene llevar el cambio también a radical para que no diverjan.

Cada skill **debe** tener un subdirectorio propio con al menos un archivo `SKILL.md`.

## Formato Requerido para SKILL.md

### Frontmatter (Obligatorio)
```yaml
---
name: webapp-testing
description: Toolkit for testing local web applications using Playwright. Use when asked to verify frontend functionality...
license: Complete terms in LICENSE.txt
---
```
- `name`: Minúsculas, guiones, máximo 64 caracteres.
- `description`: Claro y conciso. Especifica QUÉ hace y CUÁNDO debe usarse. Palabras clave que el usuario pueda mencionar en sus prompts.

### Contenido del Cuerpo (SKILL.md)
Se recomienda incluir:
- `# Título`
- `## Cuando usar este Skill`
- `## Prerrequisitos`
- `## Flujos paso a paso`
- `## Solución de problemas`
- `## Referencias`

## Empaquetado de Recursos

Los skills pueden incluir archivos adicionales.
Tipos de recursos:
- `scripts/`: Automatizaciones ejecutables (`.py`, `.sh`, `.ps1`). Se cargan cuando se ejecutan.
- `references/`: Documentación para el agente (`api_reference.md`). Se carga si se menciona.
- `assets/`: Archivos estáticos **NO** modificables por el agente (e.g. `logo.png`). No se cargan en el contexto.
- `templates/`: Código inicial o configuraciones que **el agente DEBE modificar** (`scaffold.py`).

### Referencias Relativas
Para referenciar dentro de `SKILL.md`, usa enlaces Markdown relativos:
`[script de ayuda](./scripts/helper.py)`

## Directrices de Contenido
- Escribe en modo imperativo: "Run", "Create", "Configure".
- Incluye comandos exactos.
- Para los scripts, se prefieren lenguajes multiplataforma: Python, Bash, Node.js, PowerShell.
- Evita el uso o almacenamiento de credenciales hardcodeadas en los scripts.
- Flujos largos (más de 5 pasos) deben ir en `references/`.

**Para el Agente (Antigravity):** Cuando se te pida crear o modificar un "Skill", verifica siempre el checklist anterior antes de darlo por terminado. Asegúrate de que `SKILL.md` tenga su frontmatter y descripción estructurada adecuadamente.
