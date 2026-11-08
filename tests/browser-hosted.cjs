/* Uses a synthetic worker only against localhost. Remote mode checks sign-in and layout. */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
const {execFileSync} = require('node:child_process');
const puppeteer = require(process.env.FACT_BROWSER_DRIVER || 'puppeteer-core');
(async () => {
  const base = (process.env.FACT_HOSTED_URL || 'http://127.0.0.1:8095').replace(/\/$/,'');
  const local = new URL(base).hostname === '127.0.0.1';
  const credentials = JSON.parse(fs.readFileSync(path.join(__dirname,'../storage/deploy/hosted-setup.json'),'utf8'));
  async function status(url,options={}){
    if(!process.env.FACT_SOCKS)return (await fetch(url,options)).status;
    const args=['--silent','--show-error','--max-time','30','--socks5-hostname',process.env.FACT_SOCKS,'-o','NUL','-w','%{http_code}'];
    if(options.method)args.push('-X',options.method);
    for(const [name,value]of Object.entries(options.headers||{}))args.push('-H',name+': '+value);
    if(options.body)args.push('--data-raw',options.body);
    return Number(execFileSync('curl.exe',[...args,url],{encoding:'utf8',windowsHide:true}));
  }
  assert.equal(await status(base+'/state',{headers:{Accept:'application/json'}}),401);
  assert.equal(await status(base+'/api/worker/poll',{method:'POST',headers:{'Content-Type':'application/json'},body:'{}'}),401);
  if (local) {
    const videoPath = path.join(__dirname,'../storage/jobs/9/final.mp4');
    const video = fs.existsSync(videoPath) ? fs.readFileSync(videoPath) : Buffer.concat([Buffer.from([0,0,0,24]),Buffer.from('ftypisom'),Buffer.alloc(100)]);
    const sha256 = crypto.createHash('sha256').update(video).digest('hex');
    const identity = 'cccccccccccccccccccccccccccccccc';
    const auth = {Authorization:'Bearer '+credentials.worker_token};
    const snapshot = {jobs:[{id:91,title:'Daylight is old news. Literally.',mode:'live',status:'ready',stage:'review',created_at:new Date().toISOString(),narration:'Sunlight takes about eight minutes to reach Earth. We live in the present, lit by the past.',sources:[{title:'NASA Science: the Sun',url:'https://science.nasa.gov/sun/'}],data:{video:{duration:18.23}},video_sha256:sha256,review_fingerprint:'a'.repeat(64),command_fingerprint:'b'.repeat(64),publication_started:false}],settings:{niche:'Space and astronomy explained simply',duration:'40',daily_limit:'1',publish_time:'18:00',schedule_enabled:'0',auto_publish:'0',review_required:'1',speech_limit:'2500'},monitor:{destinations:[{platform:'youtube',label:'Test channel',visibility:'private'}],issues:[]},speech_used:0,worker:false};
    let response=await fetch(base+'/api/worker/poll',{method:'POST',headers:{...auth,'Content-Type':'application/json'},body:JSON.stringify({identity,name:'Office GPU (test fixture)',control_enabled:true,snapshot})});
    assert.equal(response.status,200,await response.text());
    const form=new FormData();form.set('identity',identity);form.set('job_id','91');form.set('sha256',sha256);form.set('video',new Blob([video],{type:'video/mp4'}),'final.mp4');
    response=await fetch(base+'/api/worker/upload',{method:'POST',headers:auth,body:form});assert.equal(response.status,200,await response.text());
  }
  const browser=await puppeteer.launch({executablePath:process.env.FACT_CHROME||'C:/Program Files/Google/Chrome/Application/chrome.exe',headless:true,pipe:true,timeout:20000,args:process.env.FACT_SOCKS?['--proxy-server=socks5://'+process.env.FACT_SOCKS]:[]});
  const errors=[];
  try {
    const page=await browser.newPage();page.on('pageerror',e=>errors.push(e.message));await page.setViewport({width:1440,height:1100});
    await page.goto(base,{waitUntil:'networkidle0'});assert.match(page.url(),/login/);
    await page.type('[name=username]',credentials.username);await page.type('[name=password]',credentials.password);
    await Promise.all([page.waitForNavigation({waitUntil:'networkidle0'}),page.click('button[type=submit]')]);
    await page.waitForSelector('h1');assert.equal(await page.$eval('h1',e=>e.textContent),'Your stories, from anywhere.');
    await page.screenshot({path:path.join(__dirname,'../storage/hosted-desktop.png'),fullPage:true});
    const commandsUrl=await page.$eval('meta[name=studio-commands]',element=>element.content);
    const cookie=(await page.cookies()).map(item=>item.name+'='+item.value).join('; ');
    const csrf=await status(commandsUrl,{method:'POST',headers:{'Content-Type':'application/json',Accept:'application/json',Cookie:cookie,Origin:'https://untrusted.example','Sec-Fetch-Site':'cross-site'},body:'{}'});
    assert.equal(csrf,419);
    if(local){
      await page.click('.video-card');await page.waitForSelector('.video-detail video');
      assert.ok(await page.$('.review-note'));
      const previewUrl=await page.$eval('.video-detail video',element=>element.src);
      await page.waitForResponse(response=>response.url().endsWith('/state')&&response.status()===200,{timeout:15000});
      assert.equal(await page.$eval('.video-detail video',element=>element.src),previewUrl,'Polling must not restart the video preview');
      await page.screenshot({path:path.join(__dirname,'../storage/hosted-review.png'),fullPage:true});
      await page.click('.video-detail button.button:not(.secondary)');
      await page.waitForSelector('.message.success');
      const state=await page.evaluate(async()=> (await fetch(document.querySelector('meta[name=studio-state]').content)).json());
      assert.ok(state.commands.some(command=>command.operation==='approve'&&command.state==='queued'));
    }
    await page.setViewport({width:390,height:844});
    for(const index of [0,1,2]){await page.click(`nav button:nth-child(${index+1})`);assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),true,'Mobile overflow in view '+index);}
    await page.click('nav button:nth-child(1)');await page.screenshot({path:path.join(__dirname,'../storage/hosted-mobile.png'),fullPage:true});
    assert.deepEqual(errors,[]);console.log('PASS: hosted sign-in, protected state, worker authentication, CSRF protection, responsive Vue dashboard'+(local?', signed preview and approval command delivery.':'.'));
  } finally {await browser.close();}
})().catch(error=>{console.error(error);process.exitCode=1;});
