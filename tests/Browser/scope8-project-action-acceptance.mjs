import { chromium } from 'playwright-core';

const [baseUrl, desktopShot, mobileShot] = process.argv.slice(2);
if (!baseUrl || !desktopShot || !mobileShot) throw new Error('Usage: scope8-project-action-acceptance.mjs <base-url> <desktop-shot> <mobile-shot>');
const assert = (condition, message) => { if (!condition) throw new Error(message); };
const browser = await chromium.launch({ executablePath: 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe', headless: true });

try {
    const login = async (page, email) => {
        await page.goto(baseUrl + '/login');
        await page.locator('#email').fill(email);
        await page.locator('#password').fill('not-used');
        await Promise.all([page.waitForURL('**/company'), page.locator('button[type="submit"]').click()]);
    };
    let rpcId = 0;
    const mcp = async (request, name, args) => {
        const response = await request.post(baseUrl + '/api/mcp/rise-gate-os', {
            headers: { Authorization: 'Bearer scope8-browser', Accept: 'application/json' },
            data: { jsonrpc: '2.0', id: ++rpcId, method: 'tools/call', params: { name, arguments: args } },
        });
        assert(response.ok(), `MCP ${name} HTTP ${response.status()}`);
        const json = await response.json();
        assert(!json.result?.isError, `MCP ${name} failed: ${JSON.stringify(json)}`);
        return json.result.structuredContent;
    };
    const submitProposal = async (request, plan, requestId, key, title) => mcp(request, 'submit_proposal', {
        project_public_id: plan.public_id,
        contract_version: 'project-action.v1',
        expected_project_version: plan.plan_version,
        idempotency_key: key,
        title,
        summary: 'PurposeとExpected Outcomeを実行可能な責任単位へ展開します。',
        ai_request_public_id: requestId,
        items: [
            { operation: 'create', entity_type: 'roadmap', reference_key: `roadmap-${key}`, expected_version: plan.plan_version, attributes: { title: 'AI Launch Roadmap', purpose: 'Turn the outcome into a reviewed delivery sequence.' } },
            { operation: 'create', entity_type: 'improvement', reference_key: `theme-${key}`, parent_reference: `roadmap-${key}`, depends_on: [`roadmap-${key}`], expected_version: plan.plan_version, attributes: { title: 'AI Readiness Theme', theme_description: 'Align owner, reviewer, and delivery evidence.' } },
            { operation: 'create', entity_type: 'task', reference_key: `nested-${key}`, parent_reference: `theme-${key}`, depends_on: [`theme-${key}`], expected_version: plan.plan_version, attributes: { title: 'Review launch evidence', description: 'Review the launch inputs.', done_condition: 'Reviewer confirms the evidence.', assigned_to: 1, reviewer_user_id: 2, due_date: '2026-12-31' } },
            { operation: 'create', entity_type: 'task', reference_key: `direct-${key}`, parent_reference: plan.public_id, expected_version: plan.plan_version, attributes: { title: `Direct Action ${key}`, description: 'Coordinate the final decision.', done_condition: 'Owner records the decision.', assigned_to: 1, reviewer_user_id: 2, due_date: '2026-12-30' } },
        ],
    });

    const desktop = await browser.newContext({ viewport: { width: 1440, height: 1000 } });
    const page = await desktop.newPage();
    await login(page, 'scope8-owner@example.test');
    let response = await page.goto(baseUrl + '/company/projects/1');
    assert(response.status() === 200, 'Project page failed');
    assert(await page.getByTestId('ai-plan-entry').isVisible(), 'AI plan entry missing');
    await page.getByTestId('ai-plan-entry').click();
    assert(await page.getByText('Turn the agreed outcome into accountable action.', { exact: true }).isVisible(), 'Purpose context missing');
    assert(await page.getByText('A reviewed launch with clear ownership.', { exact: true }).isVisible(), 'Expected Outcome context missing');
    await page.locator('#ai-title').fill('Initial AI plan');
    await page.locator('#ai-instructions').fill('Start with a draft so the human can request a revision.');
    await Promise.all([page.waitForURL('**/ai-plan'), page.getByTestId('request-ai-plan').click()]);
    let requestId = await page.locator('[data-ai-request]').first().getAttribute('data-ai-request');
    await mcp(page.request, 'claim_ai_request', { request_public_id: requestId });
    let plan = await mcp(page.request, 'get_project_plan', { project_public_id: (await mcp(page.request, 'list_projects', {})).projects[0].public_id });
    let proposalResult = await submitProposal(page.request, plan, requestId, 'browser-initial', 'Initial execution plan');
    await page.reload();
    await page.goto(proposalResult.review_url);
    assert(await page.locator('[data-entity-type="task"]').count() === 2, 'Direct and nested actions were not reviewable');
    await page.locator('textarea[name="overall_feedback"]').fill('Keep the structure and make the accountability explicit.');
    await Promise.all([page.waitForURL('**/ai-plan'), page.getByTestId('request-revision').click()]);
    assert(await page.getByText('修正内容をAIへの再依頼として登録しました。').isVisible(), 'Revision request result missing');

    requestId = await page.locator('[data-ai-request]').first().getAttribute('data-ai-request');
    await mcp(page.request, 'claim_ai_request', { request_public_id: requestId });
    plan = await mcp(page.request, 'get_project_plan', { project_public_id: plan.public_id });
    proposalResult = await submitProposal(page.request, plan, requestId, 'browser-revised', 'Revised accountable execution plan');
    await page.reload();
    await page.goto(proposalResult.review_url);
    assert(await page.getByText('DIRECT ACTION', { exact: true }).isVisible(), 'Direct Action marker missing');
    assert(await page.getByText('Done Condition', { exact: true }).count() === 2, 'Done Conditions missing');
    assert(await page.getByText('Scope 8 Reviewer', { exact: true }).count() === 2, 'Reviewer missing');
    await page.getByTestId('approve-proposal').click();
    assert(await page.getByTestId('apply-proposal').isVisible(), 'Approval and Apply states were not separated');
    await page.getByTestId('apply-proposal').click();
    assert(await page.getByTestId('apply-result').isVisible(), 'Apply Result missing');
    assert((await page.getByTestId('apply-result').innerText()).includes('APPLIED'), 'Apply did not succeed');
    await page.screenshot({ path: desktopShot, fullPage: true });
    await page.getByTestId('return-to-project').click();
    assert(await page.getByText('AI Launch Roadmap', { exact: true }).isVisible(), 'Applied Roadmap missing');
    assert(await page.getByText('AI Readiness Theme', { exact: true }).isVisible(), 'Applied Action Theme missing');
    assert(await page.getByText('Direct Action browser-revised', { exact: true }).isVisible(), 'Applied direct Action missing');

    plan = await mcp(page.request, 'get_project_plan', { project_public_id: plan.public_id });
    proposalResult = await submitProposal(page.request, plan, null, 'browser-stale', 'Stale proposal');
    await page.goto(proposalResult.review_url);
    await page.getByTestId('approve-proposal').click();
    await page.goto(baseUrl + '/company/projects/1/manage');
    const definition = page.locator('form[action$="/company/projects/1"]').first();
    await definition.locator('textarea[name="purpose"]').fill('Turn the agreed outcome into accountable action with a newer version.');
    await definition.locator('button').click();
    await page.goto(proposalResult.review_url);
    await page.getByTestId('apply-proposal').click();
    assert(await page.getByTestId('apply-error').isVisible(), 'Stale apply failure was not shown');
    assert((await page.getByTestId('apply-result').innerText()).includes('CONFLICTED'), 'Stale result reason missing');
    assert((await page.getByTestId('apply-result').innerText()).includes('conflict'), 'Apply failure code missing');

    const reader = await browser.newContext({ viewport: { width: 390, height: 844 } });
    const mobile = await reader.newPage();
    await login(mobile, 'scope8-reader@example.test');
    response = await mobile.goto(baseUrl + '/company/projects/1');
    assert(response.status() === 200, 'Mobile Project page failed');
    assert(await mobile.getByTestId('ai-plan-entry').count() === 0, 'AI entry leaked to non-member');
    assert(await mobile.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth), '390px horizontal overflow');
    await mobile.screenshot({ path: mobileShot, fullPage: true });
    response = await mobile.goto(baseUrl + '/company/projects/1/ai-plan');
    assert(response.status() === 403, 'Permission negative did not deny AI journey');
    await desktop.close();
    await reader.close();
    console.log(JSON.stringify({ desktop: desktopShot, mobile: mobileShot, journey: 'request-revision-approval-apply-result-return', stale: 'conflicted', status: 'pass' }));
} finally {
    await browser.close();
}
