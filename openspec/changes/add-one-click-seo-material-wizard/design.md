## Context

The Materials dashboard already tracks keyword, title, image, and knowledge-base counts. Keyword import can be powered by DataForSEO, but the rest of the material stack is still manual. For Alex's target use case, an operator should be able to select a site, click one button, and receive enough SEO-ready materials to create safe review-gated content tasks.

## Goals / Non-Goals

**Goals:**

- Make the default workflow understandable for users who do not know SEO.
- Generate usable assets from existing site context rather than blank templates.
- Avoid consuming DataForSEO budget during material generation.
- Use AI when configured, but never make the flow fail just because AI is missing or temporarily down.
- Keep output conservative: titles, briefs, visual cards, and knowledge rules must avoid overclaiming official status.

**Non-Goals:**

- Fully autonomous publishing.
- External AI image generation.
- Replacing the existing manual library pages.
- Bulk scheduled generation.

## Decisions

- The wizard creates a new material pack per run. This keeps the action auditable and avoids silently overwriting manually curated libraries.
- Images are generated as local SVG cards with SEO alt/caption metadata. This makes the image library immediately usable without requiring a paid image model.
- The knowledge base is generated as Markdown and synced into existing knowledge chunks so current retrieval logic can use it.
- AI model usage is optional. If the configured model fails, the local deterministic planner still creates a complete pack and records the fallback state in the UI.

## Risks / Trade-offs

- Duplicate packs can accumulate if repeatedly clicked. The UI labels each run with a timestamp and the generated items are site-scoped; cleanup can be handled through existing library delete pages.
- SVG images are basic visual素材 rather than rich generated art. This is intentional for stability and cost control; external image APIs can be added later.
- AI JSON responses can be malformed. The parser validates strict keys and falls back to deterministic content.

## Validation

- PHP syntax checks for new and edited files.
- Local helper behavior with an isolated SQLite-style unit test is not viable because the app uses PostgreSQL features; validate through production-like Docker PHP syntax plus browser smoke test.
- Admin browser smoke test: open Materials, open SEO素材助手, generate a pack for selected site, verify links and counts.
