import { chromium } from 'playwright-core';
const [baseUrl,desktopShot,mobileShot]=process.argv.slice(2);if(!baseUrl||!desktopShot||!mobileShot)throw new Error('usage');
const assert=(value,message)=>{if(!value)throw new Error(message)};
const browser=await chromium.launch({executablePath:'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe',headless:true});
const login=async(page,email)=>{await page.goto(baseUrl+'/login');await page.locator('#email').fill(email);await page.locator('#password').fill('not-used');await Promise.all([page.waitForURL('**/company'),page.locator('button[type=submit]').click()]);};
try{
 const ownerContext=await browser.newContext({viewport:{width:1440,height:1000}}),owner=await ownerContext.newPage();await login(owner,'scope10-owner@example.test');
 await owner.goto(baseUrl+'/company/projects/1/manage');const create=owner.locator('form[action$="/company/projects/1/actions"]').first();
 await create.locator('[name=title]').fill('Browser notification action');await create.locator('[name=done_condition]').fill('Reviewer confirms the result');await create.locator('[name=assigned_to]').selectOption('2');await create.locator('[name=reviewer_user_id]').selectOption('3');
 await Promise.all([owner.waitForLoadState('networkidle'),create.locator('button').click()]);
 const assigneeContext=await browser.newContext({viewport:{width:1440,height:1000}}),assignee=await assigneeContext.newPage();await login(assignee,'scope10-assignee@example.test');
 await assignee.goto(baseUrl+'/company/notifications');assert(await assignee.getByText('Actionが割り当てられました',{exact:true}).isVisible(),'assignment notification missing');
 const notificationId=(await assignee.locator('a[href*="/notifications/"][href$="/open"]').first().getAttribute('href')).match(/notifications\/([^/]+)\/open/)[1];
 await assignee.locator('a[href$="/open"]').first().click();assert(assignee.url().includes('/company/projects/1'),'deep link failed');
 await assignee.goto(baseUrl+'/company/projects/1/manage');const complete=assignee.locator('form').filter({has:assignee.locator('input[name=command][value=complete]')});await Promise.all([assignee.waitForLoadState('networkidle'),complete.locator('button').click()]);
 const reviewerContext=await browser.newContext({viewport:{width:1440,height:1000}}),reviewer=await reviewerContext.newPage();await login(reviewer,'scope10-reviewer@example.test');
 await reviewer.goto(baseUrl+'/company/notifications');assert(await reviewer.getByText('確認依頼が届きました',{exact:true}).isVisible(),'review notification missing');await reviewer.screenshot({path:desktopShot,fullPage:true});
 await reviewer.locator('a[href$="/open"]').first().click();await reviewer.goto(baseUrl+'/company/projects/1/manage');const reject=reviewer.locator('form').filter({has:reviewer.locator('input[name=command][value=reject]')});await reject.locator('[name=reason]').fill('Please revise evidence');await Promise.all([reviewer.waitForLoadState('networkidle'),reject.locator('button').click()]);
 await assignee.goto(baseUrl+'/company/notifications');assert(await assignee.getByText('Actionが差し戻されました',{exact:true}).isVisible(),'return notification missing');
 const readerContext=await browser.newContext({viewport:{width:390,height:844}}),reader=await readerContext.newPage();await login(reader,'scope10-reader@example.test');let response=await reader.goto(baseUrl+'/company/notifications/'+notificationId+'/open');assert(response.status()===404,'recipient isolation failed');
 await assigneeContext.close();const mobileContext=await browser.newContext({viewport:{width:390,height:844}}),mobile=await mobileContext.newPage();await login(mobile,'scope10-assignee@example.test');await mobile.goto(baseUrl+'/company/notifications');assert(await mobile.evaluate(()=>document.documentElement.scrollWidth<=document.documentElement.clientWidth),'390px overflow');await mobile.screenshot({path:mobileShot,fullPage:true});
 response=await mobile.goto(baseUrl+'/manifest.webmanifest');assert(response.status()===200,'manifest missing');response=await mobile.goto(baseUrl+'/service-worker.js');assert(response.status()===200,'service worker missing');
 await ownerContext.close();await reviewerContext.close();await readerContext.close();await mobileContext.close();console.log(JSON.stringify({sourceJourney:true,reviewJourney:true,returnJourney:true,recipientNegative:true,desktop:true,mobile390:true,pwaAssets:true}));
}finally{await browser.close();}
