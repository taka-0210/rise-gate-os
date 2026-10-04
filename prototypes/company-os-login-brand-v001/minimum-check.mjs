import fs from "node:fs";
import path from "node:path";
import { chromium } from "playwright-core";

const baseUrl = (process.argv[2] || "http://127.0.0.1:41803").replace(/\/$/, "");
const evidenceDir = path.resolve(process.argv[3] || "storage/app/company-os-login-brand-v001-evidence");
const targetUrl = baseUrl + "/prototypes/company-os-login-brand-v001/index.html";
const targetOrigin = new URL(baseUrl).origin;
const viewports = [
    { name: "desktop-1440", width: 1440, height: 900 },
    { name: "mobile-390", width: 390, height: 844 },
    { name: "narrow-320", width: 320, height: 800 }
];

fs.mkdirSync(evidenceDir, { recursive: true });
const browser = await chromium.launch({
    executablePath: 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe',
    headless: true
});
const results = [];

try {
    for (const viewport of viewports) {
        const page = await browser.newPage({ viewport });
        const http5xx = [];
        const externalRequests = [];
        page.on("response", function (response) {
            if (response.status() >= 500) http5xx.push({ status: response.status(), url: response.url() });
        });
        page.on("request", function (request) {
            if (new URL(request.url()).origin !== targetOrigin) externalRequests.push(request.url());
        });
        await page.goto(targetUrl, { waitUntil: "networkidle" });
        const state = await page.evaluate(function () {
            const html = document.documentElement;
            const body = document.body;
            const image = document.querySelector(".brand-visual img");
            return {
                horizontalOverflow: Math.max(html.scrollWidth, body.scrollWidth) - html.clientWidth,
                brandVisualLoaded: Boolean(image && image.complete && image.naturalWidth > 0),
                title: document.querySelector("h1")?.textContent?.replace(/\s+/g, " ").trim() || ""
            };
        });
        const formVisible = await page.locator(".login-form").isVisible();
        if (state.horizontalOverflow > 1) throw new Error(viewport.name + ": horizontal overflow");
        if (!state.brandVisualLoaded) throw new Error(viewport.name + ": brand visual did not load");
        if (!formVisible) throw new Error(viewport.name + ": login form is not visible");
        if (state.title !== "CompanyOS") throw new Error(viewport.name + ": unexpected title");
        if (http5xx.length) throw new Error(viewport.name + ": HTTP 5xx detected");
        if (externalRequests.length) throw new Error(viewport.name + ": external request detected");
        await page.screenshot({ path: path.join(evidenceDir, viewport.name + ".png"), fullPage: true });
        results.push({
            viewport: viewport.name,
            horizontalOverflow: state.horizontalOverflow,
            brandVisualLoaded: state.brandVisualLoaded,
            http5xx: http5xx.length,
            externalRequests: externalRequests.length
        });
        await page.close();
    }
} finally {
    await browser.close();
}

console.log(JSON.stringify({ passed: true, targetUrl, results }, null, 2));
