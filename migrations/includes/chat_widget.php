<?php
// ============================================================
//  FILE: includes/chat_widget.php
//  Padol Court Chat Widget — AssistiveTouch Edition
//  FIX: Admin floating widget now shows player inbox list
//       (sorted by most recent message) instead of self-chat.
//       Admins can never message themselves.
// ============================================================
$_chatDismissed = ($_COOKIE['fcb_dismissed'] ?? '') === '1';
$_currentIsAdmin = isLoggedIn() && isAdmin();
?>

<?php if ($_chatDismissed): ?>
<!-- ══ DISMISSED STATE: bubble hidden, toggle still works ═══ -->
<script nonce="<?= csrfNonce() ?>">
(function(){
  window.falconChat = window.FC = {
    openPanel:function(){}, close:function(){},
    toggleBubble:function(){}, send:function(){}, init:function(){},
    mountInbox:function(el){ el.innerHTML='<div style="padding:40px;text-align:center;color:#6b7fa3;">Chat is currently hidden. Enable it from the navbar toggle.</div>'; }
  };

  function wireToggles(){
    document.querySelectorAll('.fcb-nav-toggle').forEach(function(btn){
      btn.addEventListener('click', function(e){
        e.preventDefault();
        e.stopPropagation();
        btn.style.opacity = '0.5';
        btn.style.pointerEvents = 'none';
        document.cookie = 'fcb_dismissed=;path=/;max-age=0';
        setTimeout(function(){ location.reload(); }, 120);
      });
    });
  }

  if(document.readyState === 'loading'){
    document.addEventListener('DOMContentLoaded', wireToggles);
  } else {
    wireToggles();
  }
}());
</script>
<?php return; ?>
<?php endif; ?>

<!-- ══ ACTIVE STATE: full widget ══════════════════════════════ -->
<style nonce="<?= csrfNonce() ?>">
:root{
  --cw-bg:       #080d18;
  --cw-surf:     #0d1526;
  --cw-surf2:    #111e35;
  --cw-border:   rgba(0,229,160,.15);
  --cw-accent:   #00e5a0;
  --cw-text:     #e8f0fe;
  --cw-muted:    #6b7fa3;
  --cw-danger:   #ef4444;
  --cw-mine-bg:  #00e5a0;
  --cw-mine-fg:  #05080f;
  --cw-r:        14px;
  --cw-shadow:   0 24px 64px rgba(0,0,0,.75), 0 0 0 1px rgba(0,229,160,.12);
  --cw-glow:     0 0 32px rgba(0,229,160,.35);
  --cw-font:     'Outfit','DM Sans',sans-serif;
  --cw-fhead:    'Bebas Neue',sans-serif;
  --cw-fmono:    'Space Mono','JetBrains Mono',monospace;
  --spring:      cubic-bezier(.34,1.56,.64,1);
}

#fcb-wrap{
  position:fixed;
  bottom:100px; right:24px;
  z-index:8900;
  display:flex; flex-direction:column; align-items:flex-end;
  pointer-events:none;
  will-change:transform;
  opacity:.78;
  transition:opacity .4s ease;
  user-select:none; -webkit-user-select:none;
  touch-action:none;
}
#fcb-wrap:hover,
#fcb-wrap.active,
#fcb-wrap.dragging{ opacity:1; }
#fcb-wrap.snapping{
  transition:transform .42s var(--spring), opacity .4s ease;
}

#fcb-lbl{
  pointer-events:auto;
  background:var(--cw-surf); border:1px solid var(--cw-border);
  border-radius:20px; padding:6px 14px; margin-bottom:10px;
  font-family:var(--cw-font); font-size:12px; font-weight:600;
  color:var(--cw-text); white-space:nowrap;
  box-shadow:0 4px 16px rgba(0,0,0,.45);
  cursor:pointer; position:relative;
  transform:translateY(4px) scale(.95); opacity:0;
  animation:fcbLblIn .5s var(--spring) .6s forwards;
  transition:background .2s, border-color .2s, transform .2s;
}
@keyframes fcbLblIn{ to{ transform:translateY(0) scale(1); opacity:1; } }
#fcb-lbl::after{
  content:''; position:absolute; bottom:-6px; right:22px;
  width:10px; height:6px; background:var(--cw-surf);
  clip-path:polygon(0 0,100% 0,50% 100%);
  border-left:1px solid var(--cw-border);
  border-right:1px solid var(--cw-border);
}
#fcb-lbl:hover{ background:rgba(0,229,160,.1); border-color:var(--cw-accent); transform:translateY(-1px); }
#fcb-lbl.gone{ display:none; }

#fcb-btn{
  pointer-events:auto;
  width:56px; height:56px; border-radius:50%;
  background:linear-gradient(135deg,var(--cw-accent),#00c87a);
  border:none; cursor:grab;
  display:flex; align-items:center; justify-content:center;
  box-shadow:var(--cw-glow), 0 8px 24px rgba(0,0,0,.5);
  transition:box-shadow .25s ease, background .25s ease;
  position:relative; flex-shrink:0;
  -webkit-tap-highlight-color:transparent;
  touch-action:none;
  animation:fcbBtnIn .6s var(--spring) .1s both, fcbFloat 3.5s ease-in-out 1.5s infinite;
}
@keyframes fcbBtnIn{
  from{ transform:scale(0) rotate(-20deg); opacity:0; }
  to  { transform:scale(1) rotate(0); opacity:1; }
}
@keyframes fcbFloat{
  0%,100%{ transform:translateY(0); }
  50%    { transform:translateY(-4px); }
}
#fcb-wrap.dragging #fcb-btn{ animation:none; cursor:grabbing; }
#fcb-btn.open{
  background:linear-gradient(135deg,#ff6b35,#e0552a);
  animation:none !important;
  box-shadow:0 0 32px rgba(255,107,53,.4), 0 8px 24px rgba(0,0,0,.5);
}

.fcb-ball { width:32px;height:32px;pointer-events:none; }
.fcb-x    { display:none;font-size:20px;color:#fff;font-weight:300;line-height:1;pointer-events:none; }
#fcb-btn.open .fcb-ball{ display:none; }
#fcb-btn.open .fcb-x   { display:block; }

#fcb-badge{
  position:absolute; top:-3px; right:-3px;
  background:var(--cw-danger); color:#fff;
  font-size:10px; font-weight:800; border-radius:99px;
  padding:2px 5px; min-width:18px; text-align:center;
  border:2px solid #05080f; display:none; pointer-events:none;
  animation:cwPop .3s var(--spring);
}
#fcb-badge.on{ display:block; }
@keyframes cwPop{ from{transform:scale(0);}to{transform:scale(1);} }

#fcb-dz{
  position:fixed; bottom:-100px; left:50%;
  transform:translateX(-50%);
  z-index:9000;
  width:68px; height:68px; border-radius:50%;
  background:rgba(239,68,68,.15);
  border:2px dashed rgba(239,68,68,.5);
  display:flex; align-items:center; justify-content:center;
  font-size:22px; color:rgba(239,68,68,.8);
  transition:bottom .4s var(--spring), background .2s, border-color .2s, transform .25s var(--spring);
  pointer-events:none; user-select:none;
}
#fcb-dz.up   { bottom:32px; }
#fcb-dz.over {
  background:rgba(239,68,68,.4); border-color:#ef4444;
  transform:translateX(-50%) scale(1.2); color:#fff;
}
#fcb-dz::after{
  content:'remove'; position:absolute; bottom:-20px;
  left:50%; transform:translateX(-50%);
  font-size:9px; font-weight:700; letter-spacing:.08em;
  text-transform:uppercase; color:rgba(239,68,68,.65);
  white-space:nowrap; font-family:var(--cw-font,sans-serif);
}

#fcb-panel{
  position:fixed; bottom:88px; right:24px; z-index:8800;
  width:360px; height:600px; max-height:calc(100dvh - 108px);
  display:flex; flex-direction:column;
  background:var(--cw-bg);
  border:1px solid var(--cw-border);
  border-radius:18px;
  box-shadow:var(--cw-shadow);
  overflow:hidden;
  transform-origin:bottom right;
  transform:scale(.88) translateY(16px);
  opacity:0; pointer-events:none;
  transition:transform .38s var(--spring), opacity .28s ease;
}
#fcb-panel.open{
  transform:scale(1) translateY(0);
  opacity:1; pointer-events:all;
}

.ph{
  background:linear-gradient(135deg,var(--cw-surf),var(--cw-surf2));
  border-bottom:1px solid var(--cw-border);
  padding:13px 15px;
  display:flex; align-items:center; gap:10px; flex-shrink:0;
}
.ph-ico{
  width:34px;height:34px;
  background:linear-gradient(135deg,var(--cw-accent),#00c87a);
  border-radius:50%;
  display:flex;align-items:center;justify-content:center;flex-shrink:0;
}
.ph-inf{ flex:1;min-width:0; }
.ph-title{ font-family:var(--cw-fhead);font-size:16px;letter-spacing:1px;color:var(--cw-text);line-height:1.1; }
.ph-sub  { font-size:11px;color:var(--cw-muted);margin-top:2px;display:flex;align-items:center;gap:4px; }
.ph-dot  { width:6px;height:6px;background:var(--cw-accent);border-radius:50%;flex-shrink:0;animation:pdot 2s ease-in-out infinite; }
@keyframes pdot{0%,100%{opacity:1;transform:scale(1);}50%{opacity:.4;transform:scale(1.4);}}
.ph-close{
  background:none;border:none;color:var(--cw-muted);font-size:17px;
  cursor:pointer;padding:4px;border-radius:7px;
  transition:color .2s,background .2s;line-height:1;
  width:30px;height:30px;display:flex;align-items:center;justify-content:center;
  flex-shrink:0;touch-action:manipulation;
}
.ph-close:hover{color:var(--cw-text);background:rgba(255,255,255,.07);}
.ph-back{display:none;background:none;border:none;color:var(--cw-muted);font-size:17px;cursor:pointer;padding:4px 6px 4px 0;transition:color .2s;touch-action:manipulation;}
.ph-back.on{display:flex;align-items:center;}
.ph-back:hover{color:var(--cw-accent);}

.pm{
  flex:1;overflow-y:auto;overflow-x:hidden;
  padding:12px 11px;
  display:flex;flex-direction:column;gap:5px;
  min-height:0;
  overscroll-behavior:contain;
  -webkit-overflow-scrolling:touch;
  scroll-behavior:smooth;
}
.pm::-webkit-scrollbar{width:2px;}
.pm::-webkit-scrollbar-thumb{background:rgba(0,229,160,.35);border-radius:99px;}

.pg{flex:1;display:flex;flex-direction:column;align-items:center;justify-content:center;padding:24px 20px;gap:12px;text-align:center;}
.pg-ico  {font-size:46px;line-height:1;}
.pg-title{font-family:var(--cw-fhead);font-size:22px;letter-spacing:1px;color:var(--cw-text);}
.pg-sub  {font-size:13px;color:var(--cw-muted);line-height:1.6;}
.pg-btns {display:flex;flex-direction:column;gap:8px;width:100%;margin-top:4px;}
.pg-btn  {display:flex;align-items:center;justify-content:center;gap:8px;padding:11px 18px;border-radius:10px;font-family:var(--cw-font);font-size:14px;font-weight:600;text-decoration:none;cursor:pointer;transition:all .2s;border:none;min-height:44px;touch-action:manipulation;}
.pg-btn.p{background:var(--cw-accent);color:#05080f;}
.pg-btn.p:hover{background:#00ffb2;transform:translateY(-1px);}
.pg-btn.s{background:var(--cw-surf2);border:1px solid var(--cw-border);color:var(--cw-text);}
.pg-btn.s:hover{border-color:var(--cw-accent);background:rgba(0,229,160,.06);}

.msg{
  display:flex;flex-direction:column;max-width:80%;
  animation:msgIn .22s var(--spring);
}
@keyframes msgIn{from{opacity:0;transform:translateY(6px) scale(.96);}to{opacity:1;transform:translateY(0) scale(1);}}
.msg.me {align-self:flex-end; align-items:flex-end;}
.msg.you{align-self:flex-start;align-items:flex-start;}
.msg-bbl{
  padding:8px 12px;border-radius:16px;
  font-size:13.5px;line-height:1.55;word-break:break-word;
  font-family:var(--cw-font);
}
.msg.me  .msg-bbl{background:var(--cw-mine-bg);color:var(--cw-mine-fg);border-bottom-right-radius:4px;}
.msg.you .msg-bbl{background:var(--cw-surf2);color:var(--cw-text);border:1px solid var(--cw-border);border-bottom-left-radius:4px;}
.msg-meta{font-size:10px;color:var(--cw-muted);margin-top:3px;display:flex;align-items:center;gap:3px;}
.msg.me .msg-meta{flex-direction:row-reverse;}
.admin-lbl{color:var(--cw-accent);font-weight:700;}

.ptyp{display:none;align-items:center;gap:4px;padding:6px 0 2px 4px;}
.ptyp.on{display:flex;}
.ptyp span{width:5px;height:5px;background:var(--cw-muted);border-radius:50%;animation:ptyp 1.4s ease-in-out infinite;}
.ptyp span:nth-child(2){animation-delay:.2s;}.ptyp span:nth-child(3){animation-delay:.4s;}
@keyframes ptyp{0%,60%,100%{transform:translateY(0);opacity:.4;}30%{transform:translateY(-5px);opacity:1;}}

.pempty{flex:1;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:9px;color:var(--cw-muted);font-size:13px;text-align:center;padding:20px;}
.pempty-ico{font-size:34px;}

.pdate{
  display:flex;align-items:center;gap:8px;
  margin:8px 4px;font-size:10px;color:var(--cw-muted);
  font-family:var(--cw-fmono);letter-spacing:.07em;text-transform:uppercase;flex-shrink:0;
}
.pdate::before,.pdate::after{content:'';flex:1;height:1px;background:var(--cw-border);}

.pia{
  border-top:1px solid var(--cw-border);
  padding:9px 11px;
  display:flex;align-items:flex-end;gap:8px;
  background:var(--cw-surf);flex-shrink:0;
}
.piw{
  flex:1;position:relative;display:flex;align-items:center;
  background:var(--cw-bg);border:1px solid var(--cw-border);
  border-radius:22px;overflow:hidden;
  transition:border-color .2s,box-shadow .2s;min-height:38px;
}
.piw:focus-within{border-color:var(--cw-accent);box-shadow:0 0 0 3px rgba(0,229,160,.08);}
.pibtn{background:none;border:none;font-size:16px;padding:0 0 0 11px;cursor:pointer;line-height:1;transition:transform .2s;flex-shrink:0;touch-action:manipulation;min-height:34px;display:flex;align-items:center;}
.pibtn:hover{transform:scale(1.2);}
.pitxt{flex:1;background:none;border:none;outline:none;padding:7px 10px 7px 6px;font-family:var(--cw-font);font-size:13.5px;color:var(--cw-text);resize:none;min-height:22px;max-height:90px;line-height:1.45;-webkit-appearance:none;}
.pitxt::placeholder{color:var(--cw-muted);}
.psend{
  width:36px;height:36px;
  background:var(--cw-accent);border:none;border-radius:50%;
  cursor:pointer;display:flex;align-items:center;justify-content:center;
  transition:background .2s, transform .2s, box-shadow .2s;
  flex-shrink:0;touch-action:manipulation;
}
.psend:hover{background:#00ffb2;transform:scale(1.1);box-shadow:0 0 16px rgba(0,229,160,.4);}
.psend:disabled{background:var(--cw-surf2);cursor:not-allowed;transform:none;box-shadow:none;}
.psend svg{color:#05080f;}

.pep{
  position:absolute;bottom:56px;left:0;width:100%;
  background:var(--cw-surf);border-top:1px solid var(--cw-border);
  display:none;flex-wrap:wrap;padding:7px;gap:3px;
  max-height:120px;overflow-y:auto;z-index:10;
}
.pep.on{display:flex;}
.pepi{font-size:20px;cursor:pointer;padding:4px 5px;border-radius:6px;transition:background .12s,transform .12s;line-height:1;touch-action:manipulation;}
.pepi:hover{background:rgba(0,229,160,.12);transform:scale(1.22);}

/* ── Admin floating inbox list ─────────────────────────── */
.fcw-inbox{
  flex:1;overflow-y:auto;overflow-x:hidden;
  display:flex;flex-direction:column;
  min-height:0;
  overscroll-behavior:contain;
  -webkit-overflow-scrolling:touch;
}
.fcw-inbox::-webkit-scrollbar{width:2px;}
.fcw-inbox::-webkit-scrollbar-thumb{background:rgba(0,229,160,.35);border-radius:99px;}

.fcw-inbox-search{
  padding:10px 12px 8px;
  flex-shrink:0;
  border-bottom:1px solid var(--cw-border);
}
.fcw-inbox-search input{
  width:100%;background:var(--cw-bg);border:1px solid var(--cw-border);
  border-radius:20px;padding:7px 14px;font-size:12px;color:var(--cw-text);
  font-family:var(--cw-font);outline:none;box-sizing:border-box;
  transition:border-color .2s;
}
.fcw-inbox-search input:focus{border-color:var(--cw-accent);}
.fcw-inbox-search input::placeholder{color:var(--cw-muted);}

.fcw-conv-list{flex:1;overflow-y:auto;padding:6px;}
.fcw-conv-list::-webkit-scrollbar{width:2px;}
.fcw-conv-list::-webkit-scrollbar-thumb{background:rgba(0,229,160,.3);border-radius:99px;}

.fcw-conv-item{
  display:flex;align-items:center;gap:10px;
  padding:10px 10px;border-radius:10px;cursor:pointer;
  border:1px solid transparent;margin-bottom:2px;
  transition:background .15s,border-color .15s;
  animation:msgIn .2s var(--spring);
}
.fcw-conv-item:hover{background:rgba(0,229,160,.07);border-color:rgba(0,229,160,.2);}
.fcw-conv-item:active{background:rgba(0,229,160,.13);}

.fcw-conv-avatar{
  width:36px;height:36px;border-radius:50%;
  display:flex;align-items:center;justify-content:center;
  font-size:13px;font-weight:700;color:#05080f;
  flex-shrink:0;position:relative;
}
.fcw-conv-unread-dot{
  position:absolute;top:-1px;right:-1px;
  width:9px;height:9px;background:var(--cw-danger);
  border-radius:50%;border:2px solid var(--cw-bg);
}
.fcw-conv-info{flex:1;min-width:0;}
.fcw-conv-name{
  font-size:13px;font-weight:700;color:var(--cw-text);
  overflow:hidden;text-overflow:ellipsis;white-space:nowrap;
}
.fcw-conv-preview{
  font-size:11px;color:var(--cw-muted);
  overflow:hidden;text-overflow:ellipsis;white-space:nowrap;
  margin-top:2px;
}
.fcw-conv-meta{
  display:flex;flex-direction:column;align-items:flex-end;gap:3px;flex-shrink:0;
}
.fcw-conv-time{font-size:10px;color:var(--cw-muted);white-space:nowrap;}
.fcw-conv-badge{
  background:var(--cw-danger);color:#fff;font-size:10px;font-weight:800;
  border-radius:99px;padding:2px 6px;min-width:18px;text-align:center;
}

.fcw-inbox-footer{
  padding:8px 12px;border-top:1px solid var(--cw-border);
  flex-shrink:0;
}
.fcw-inbox-full-link{
  display:flex;align-items:center;justify-content:center;gap:6px;
  padding:9px;border-radius:8px;font-size:12px;font-weight:700;
  color:var(--cw-accent);text-decoration:none;
  background:rgba(0,229,160,.07);border:1px solid rgba(0,229,160,.18);
  transition:background .15s;
}
.fcw-inbox-full-link:hover{background:rgba(0,229,160,.15);}

.fcp-nav-icon{position:relative;background:none;border:none;cursor:pointer;padding:6px 10px;border-radius:8px;color:var(--muted,#6b7fa3);font-size:18px;display:flex;align-items:center;justify-content:center;transition:color .2s,background .2s;min-width:38px;min-height:38px;touch-action:manipulation;text-decoration:none;white-space:nowrap;}
.fcp-nav-icon:hover{color:var(--text,#e8f0fe);background:var(--surface2,#111e35);}
.fcp-nav-badge{position:absolute;top:2px;right:2px;background:var(--cw-danger);color:#fff;font-size:9px;font-weight:800;border-radius:99px;padding:1px 5px;min-width:16px;text-align:center;display:none;font-family:var(--cw-font);line-height:1.4;}
.fcp-nav-badge.show{display:block;}

.falcon-chat-embedded{display:flex;flex-direction:column;height:100%;overflow:hidden;}

@media(max-width:768px){
  #fcb-panel{
    position:fixed;bottom:0;left:0;right:0;
    width:100%;height:94dvh;max-height:94dvh;
    border-radius:22px 22px 0 0;
    transform-origin:bottom center;
    z-index:9100;
  }
  #fcb-panel.open{transform:scale(1) translateY(0);opacity:1;}
  #fcb-panel .ph    {flex-shrink:0;z-index:2;background:linear-gradient(135deg,var(--cw-surf),var(--cw-surf2));}
  #fcb-panel #fcp-body{flex:1;min-height:0;display:flex;flex-direction:column;overflow:hidden;}
  #fcb-panel .pm    {flex:1;min-height:0;overflow-y:auto;-webkit-overflow-scrolling:touch;}
  #fcb-panel .pia   {flex-shrink:0;padding-bottom:max(10px,env(safe-area-inset-bottom));}
  #fcb-panel .pep   {position:relative;bottom:unset;max-height:110px;}
  .ph-close{min-width:36px;min-height:36px;}
  #fcb-wrap{bottom:max(88px,calc(env(safe-area-inset-bottom)+72px));right:16px;}
  #fcb-btn {width:50px;height:50px;}
  #fcb-lbl {font-size:11px;}
  #fcb-dz  {width:76px;height:76px;font-size:24px;}
}
@media(max-width:360px){
  #fcb-lbl{font-size:10px;padding:5px 10px;}
  .ph-title{font-size:15px;}
  .msg-bbl{font-size:13px;padding:7px 10px;}
}
</style>

<div id="fcb-dz">✕</div>

<div id="fcb-wrap" style="display:none;" aria-hidden="true">
  <div id="fcb-lbl">💬 <?= $_currentIsAdmin ? 'Player Messages' : 'Padol Chat' ?></div>
  <button id="fcb-btn" aria-label="Open Padol Chat" aria-expanded="false">
    <svg class="fcb-ball" viewBox="0 0 48 48" fill="none" xmlns="http://www.w3.org/2000/svg">
      <circle cx="24" cy="24" r="20" fill="#05080f" stroke="rgba(5,8,15,.4)" stroke-width="1"/>
      <circle cx="24" cy="24" r="18" fill="#05080f"/>
      <circle cx="15" cy="17" r="2.4" fill="#00e5a0" opacity=".9"/>
      <circle cx="24" cy="14" r="2.4" fill="#00e5a0" opacity=".9"/>
      <circle cx="33" cy="17" r="2.4" fill="#00e5a0" opacity=".9"/>
      <circle cx="12" cy="26" r="2.4" fill="#00e5a0" opacity=".9"/>
      <circle cx="21" cy="24" r="2.4" fill="#00e5a0" opacity=".9"/>
      <circle cx="30" cy="24" r="2.4" fill="#00e5a0" opacity=".9"/>
      <circle cx="36" cy="26" r="2.4" fill="#00e5a0" opacity=".9"/>
      <circle cx="15" cy="33" r="2.4" fill="#00e5a0" opacity=".9"/>
      <circle cx="24" cy="36" r="2.4" fill="#00e5a0" opacity=".9"/>
      <circle cx="33" cy="33" r="2.4" fill="#00e5a0" opacity=".9"/>
      <path d="M17 19.5h14M17 24.5h9" stroke="#00e5a0" stroke-width="1.8" stroke-linecap="round" opacity=".55"/>
    </svg>
    <span class="fcb-x">✕</span>
    <div id="fcb-badge"></div>
  </button>
</div>

<div id="fcb-panel" role="dialog" aria-label="Padol Chat" aria-hidden="true">
  <div class="ph">
    <div class="ph-ico">
      <svg viewBox="0 0 28 28" width="20" height="20" fill="none">
        <circle cx="14" cy="14" r="12" fill="#05080f"/>
        <circle cx="10" cy="11" r="1.7" fill="#00e5a0" opacity=".7"/>
        <circle cx="14" cy="9.5" r="1.7" fill="#00e5a0" opacity=".7"/>
        <circle cx="18" cy="11" r="1.7" fill="#00e5a0" opacity=".7"/>
        <circle cx="9"  cy="15.5" r="1.7" fill="#00e5a0" opacity=".7"/>
        <circle cx="14" cy="15"   r="1.7" fill="#00e5a0" opacity=".7"/>
        <circle cx="19" cy="15.5" r="1.7" fill="#00e5a0" opacity=".7"/>
        <circle cx="10" cy="20"   r="1.7" fill="#00e5a0" opacity=".7"/>
        <circle cx="14" cy="21"   r="1.7" fill="#00e5a0" opacity=".7"/>
        <circle cx="18" cy="20"   r="1.7" fill="#00e5a0" opacity=".7"/>
      </svg>
    </div>
    <div class="ph-inf">
      <div class="ph-title" id="fcp-panel-title"><?= $_currentIsAdmin ? 'PLAYER MESSAGES' : 'FALCON CHAT' ?></div>
      <div class="ph-sub"><span class="ph-dot"></span><span id="fcp-status"><?= $_currentIsAdmin ? 'Inbox' : 'Court Support' ?></span></div>
    </div>
    <button class="ph-back" id="fcp-ph-back" aria-label="Back to inbox">◀</button>
    <button class="ph-close" onclick="FC.close()" aria-label="Close">✕</button>
  </div>
  <div id="fcp-body" style="display:flex;flex-direction:column;flex:1;min-height:0;"></div>
  <div class="pep" id="pep"></div>
  <div class="pia" id="pia" style="display:none;">
    <div class="piw">
      <button class="pibtn" onclick="FC.toggleEmoji()" title="Emoji" aria-label="Emoji">😊</button>
      <textarea class="pitxt" id="pitxt" placeholder="Type a message…" rows="1"
                aria-label="Chat message"
                onkeydown="FC.handleKey(event)"
                oninput="FC.resize(this)"></textarea>
    </div>
    <button class="psend" id="psend" onclick="FC.send()" aria-label="Send">
      <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
        <line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/>
      </svg>
    </button>
  </div>
</div>

<script nonce="<?= csrfNonce() ?>">
(function(){
'use strict';

var API = (window.APP_URL || '') + '/api/chat.php';
var POLL_MS = 3000;
var INBOX_REFRESH_MS = 6000;
var EDGE_PAD= 18;
var IDLE_MS = 2200;
// Set by PHP so JS knows role without an extra fetch
var CURRENT_IS_ADMIN = <?= $_currentIsAdmin ? 'true' : 'false' ?>;

var EMOJIS=['😊','😄','😂','🤣','❤️','🏓','🎾','🏅','🥇','🎮','👏','🙌','🤝',
  '💪','🔥','⚡','✅','👋','😅','🥳','🎉','🤔','👍','👎','😢','😮',
  '🙏','💯','🚀','🌟','😎','🤩','😬','👀','💬','📢','⏰','🏆','🎯'];

// ── State ─────────────────────────────────────────────────
var panelOpen=false, loggedIn=false, isAdmin=false;
var lastId=0, pollTimer=null, targetUid=null, targetUsername=null;
var emojiOpen=false, sending=false, ready=false;
var inboxOpen=false;
// Admin floating panel state: 'inbox' | 'conversation'
var adminView='inbox';
var inboxRefreshTimer=null;
var inboxConversations=[];

// ── DOM ───────────────────────────────────────────────────
var $wrap  = document.getElementById('fcb-wrap');
var $btn   = document.getElementById('fcb-btn');
var $lbl   = document.getElementById('fcb-lbl');
var $panel = document.getElementById('fcb-panel');
var $body  = document.getElementById('fcp-body');
var $txt   = document.getElementById('pitxt');
var $snd   = document.getElementById('psend');
var $ia    = document.getElementById('pia');
var $badge = document.getElementById('fcb-badge');
var $ep    = document.getElementById('pep');
var $st    = document.getElementById('fcp-status');
var $dz    = document.getElementById('fcb-dz');
var $phBack= document.getElementById('fcp-ph-back');

/* ═══════════════════════════════════════════════════════════
   POSITION ENGINE
   ═══════════════════════════════════════════════════════════ */
var pos = { x:0, y:0 };
var vel = { x:0, y:0 };

function applyTransform(x,y,animate){
  if(animate){ $wrap.classList.add('snapping'); }
  else        { $wrap.classList.remove('snapping'); }
  $wrap.style.transform='translate('+x+'px,'+y+'px)';
  pos.x=x; pos.y=y;
}

function wrapRect(){ return $wrap.getBoundingClientRect(); }

function snapToEdge(vx){
  var r=wrapRect(), vw=window.innerWidth, mid=r.left+r.width/2;
  var goRight = vx>60 ? true : vx<-60 ? false : (mid > vw/2);
  var targetX = goRight
    ? pos.x+(vw - r.right - EDGE_PAD)
    : pos.x-(r.left - EDGE_PAD);
  var targetY = pos.y;
  var top=r.top, bot=r.bottom, vh=window.innerHeight;
  if(top < EDGE_PAD)     targetY += (EDGE_PAD-top);
  if(bot > vh-EDGE_PAD)  targetY -= (bot-(vh-EDGE_PAD));
  applyTransform(targetX,targetY,true);
  savePos(targetX,targetY);
  setTimeout(function(){
    if(!panelOpen) $btn.style.animation='fcbFloat 3.5s ease-in-out infinite';
  },450);
}

function savePos(x,y){
  try{ localStorage.setItem('fcb_pos',JSON.stringify({x:x,y:y})); }catch(e){}
}
function loadPos(){
  try{
    var d=JSON.parse(localStorage.getItem('fcb_pos')||'null');
    if(d&&typeof d.x==='number'){ applyTransform(d.x,d.y,false); return true; }
  }catch(e){}
  return false;
}

/* ═══════════════════════════════════════════════════════════
   DRAG ENGINE
   ═══════════════════════════════════════════════════════════ */
var dragging=false, hasMoved=false, overDZ=false;
var startX=0,startY=0, lastX=0,lastY=0, lastT=0;
var startPosX=0,startPosY=0;
var idleTimer=null;

function setIdle(){ clearTimeout(idleTimer); $wrap.classList.remove('active'); }
function cancelIdle(){ clearTimeout(idleTimer); $wrap.classList.add('active'); }

$btn.addEventListener('pointerdown',function(e){
  if(e.button&&e.button!==0) return;
  if(panelOpen){ e.preventDefault(); closePanel(); return; }
  dragging=true; hasMoved=false;
  startX=lastX=e.clientX; startY=lastY=e.clientY;
  startPosX=pos.x; startPosY=pos.y;
  lastT=Date.now();
  vel.x=0; vel.y=0;
  $btn.setPointerCapture(e.pointerId);
  $btn.style.animation='none';
  cancelIdle();
  e.preventDefault();
},{passive:false});

$btn.addEventListener('pointermove',function(e){
  if(!dragging) return;
  var dx=e.clientX-startX, dy=e.clientY-startY;
  if(!hasMoved && Math.sqrt(dx*dx+dy*dy)<5) return;
  if(!hasMoved){
    hasMoved=true;
    $wrap.classList.add('dragging');
    $lbl.classList.add('gone');
    $dz.classList.add('up');
    $wrap.classList.remove('snapping');
  }
  var now=Date.now(), dt=Math.max(now-lastT,1);
  vel.x=(e.clientX-lastX)/dt*16;
  vel.y=(e.clientY-lastY)/dt*16;
  lastX=e.clientX; lastY=e.clientY; lastT=now;
  var nx=startPosX+dx, ny=startPosY+dy;
  var r=$wrap.getBoundingClientRect();
  var vw=window.innerWidth, vh=window.innerHeight;
  var minX=-(r.left-EDGE_PAD/2), maxX=vw-r.right-EDGE_PAD/2;
  var minY=-(r.top-EDGE_PAD/2),  maxY=vh-r.bottom-EDGE_PAD/2;
  nx=clampRubber(nx,pos.x+minX,pos.x+maxX,20);
  ny=clampRubber(ny,pos.y+minY,pos.y+maxY,20);
  applyTransform(nx,ny,false);
  var dzR=$dz.getBoundingClientRect();
  var hit=e.clientX>=dzR.left&&e.clientX<=dzR.right&&e.clientY>=dzR.top&&e.clientY<=dzR.bottom;
  if(hit!==overDZ){
    overDZ=hit;
    $dz.classList.toggle('over',hit);
    $btn.style.background=hit
      ?'linear-gradient(135deg,#ef4444,#dc2626)'
      :'linear-gradient(135deg,#00e5a0,#00c87a)';
    $btn.style.transform=hit?'scale(.84)':'scale(1.08)';
  }
},{passive:true});

$btn.addEventListener('pointerup',function(e){
  if(!dragging) return;
  dragging=false;
  $wrap.classList.remove('dragging');
  $btn.style.transform=''; $btn.style.background='';
  $dz.classList.remove('up','over');
  if(!hasMoved){ openPanel(); return; }
  if(overDZ){ overDZ=false; dismiss(); return; }
  overDZ=false;
  snapToEdge(vel.x);
  idleTimer=setTimeout(setIdle, IDLE_MS);
});

$btn.addEventListener('pointercancel',function(){
  if(!dragging) return;
  dragging=false;
  $wrap.classList.remove('dragging');
  $btn.style.transform=''; $btn.style.background='';
  $dz.classList.remove('up','over');
  snapToEdge(0);
  idleTimer=setTimeout(setIdle,IDLE_MS);
});

function clampRubber(val,min,max,rubber){
  if(val<min) return min-(min-val)*rubber/(rubber+Math.abs(min-val));
  if(val>max) return max+(val-max)*rubber/(rubber+Math.abs(val-max));
  return val;
}

$lbl.addEventListener('click',function(){ if(!panelOpen) openPanel(); });
$wrap.addEventListener('mouseenter',cancelIdle);
$wrap.addEventListener('mouseleave',function(){ if(!dragging&&!panelOpen) idleTimer=setTimeout(setIdle,IDLE_MS); });

/* ═══════════════════════════════════════════════════════════
   DISMISS / RESTORE
   ═══════════════════════════════════════════════════════════ */
function dismiss(){
  if(panelOpen) closePanel();
  $wrap.style.transition='transform .3s cubic-bezier(.4,0,.2,1), opacity .25s ease';
  $wrap.style.transform='translate('+pos.x+'px,'+pos.y+'px) scale(0)';
  $wrap.style.opacity='0';
  $panel.style.transition='opacity .2s';
  $panel.style.opacity='0';
  document.cookie='fcb_dismissed=1;path=/;max-age='+(365*24*3600);
  setTimeout(function(){ location.reload(); }, 320);
}

function restore(){
  document.cookie='fcb_dismissed=;path=/;max-age=0';
  document.querySelectorAll('.fcb-nav-toggle').forEach(function(b){
    b.style.opacity='0.5';
    b.style.pointerEvents='none';
  });
  setTimeout(function(){ location.reload(); }, 120);
}

/* ═══════════════════════════════════════════════════════════
   NAVBAR TOGGLE SYNC
   ═══════════════════════════════════════════════════════════ */
function syncNavToggle(isOn){
  document.querySelectorAll('.fcb-nav-toggle').forEach(function(btn){
    btn.classList.toggle('fcb-nt-on',  isOn);
    btn.classList.toggle('fcb-nt-off', !isOn);
    var lbl = btn.querySelector('.fcb-nt-label');
    if(lbl) lbl.textContent = isOn ? 'Chat: ON' : 'Chat: OFF';
    var tip = isOn ? 'Click to hide the chat bubble' : 'Click to show the chat bubble';
    btn.setAttribute('data-tip', tip);
    btn.setAttribute('aria-label', tip);
    btn.setAttribute('aria-pressed', isOn ? 'true' : 'false');
  });
}

/* ═══════════════════════════════════════════════════════════
   INIT
   ═══════════════════════════════════════════════════════════ */
async function init(){
  if(ready) return; ready=true;

  EMOJIS.forEach(function(e){
    var b=document.createElement('button');
    b.className='pepi'; b.textContent=e; b.type='button';
    b.onclick=function(){ insertEmoji(e); };
    $ep.appendChild(b);
  });

  try{
    var r=await fetch(API+'?action=status',{credentials:'same-origin'});
    var d=await r.json();
    loggedIn=d.loggedIn; isAdmin=d.isAdmin;
  }catch(e){ loggedIn=false; }

  // Wire up back button for admin conversation view
  if($phBack){
    $phBack.addEventListener('click', function(){
      if(isAdmin && adminView==='conversation'){
        showAdminInboxView();
      }
    });
  }

  // Wire up all navbar toggle buttons (desktop + mobile)
  document.querySelectorAll('.fcb-nav-toggle').forEach(function(btn){
    btn.addEventListener('click', function(ev){
      ev.preventDefault();
      ev.stopPropagation();
      var isOn=btn.classList.contains('fcb-nt-on');
      if(isOn){ dismiss(); } else { restore(); }
    });
  });

  loadPos();
  $wrap.style.display='flex';
  $wrap.removeAttribute('aria-hidden');

  setTimeout(function(){ if($lbl&&!panelOpen) $lbl.classList.add('gone'); },4000);
  idleTimer=setTimeout(setIdle,3000);

  pollBadge();
  setInterval(pollBadge,8000);
}

/* ═══════════════════════════════════════════════════════════
   PANEL (floating bubble panel)
   ═══════════════════════════════════════════════════════════ */
function openPanel(){
  if(panelOpen||inboxOpen) return;
  panelOpen=true;
  cancelIdle();
  $btn.classList.add('open');
  $btn.setAttribute('aria-expanded','true');
  $btn.style.animation='none';
  $panel.classList.add('open');
  $panel.setAttribute('aria-hidden','false');
  $lbl.classList.add('gone');
  $wrap.classList.add('active');
  $wrap.style.visibility='hidden';

  if(isAdmin){
    // Admin: show inbox list, never self-chat
    adminView='inbox';
    showAdminInboxView();
  } else {
    renderBody();
    if(loggedIn) startPoll();
  }
}

function closePanel(){
  if(!panelOpen) return;
  panelOpen=false;
  adminView='inbox';
  targetUid=null; targetUsername=null;
  $btn.classList.remove('open');
  $btn.setAttribute('aria-expanded','false');
  $panel.classList.remove('open');
  $panel.setAttribute('aria-hidden','true');
  stopPoll();
  stopInboxRefresh();
  $ep.classList.remove('on'); emojiOpen=false;
  if($ia) $ia.style.display='none';
  if($phBack) $phBack.classList.remove('on');
  var pt=document.getElementById('fcp-panel-title');
  if(pt) pt.textContent= CURRENT_IS_ADMIN ? 'PLAYER MESSAGES' : 'FALCON CHAT';
  if($st) $st.textContent= CURRENT_IS_ADMIN ? 'Inbox' : 'Court Support';
  $wrap.style.visibility='visible';
  $wrap.classList.remove('active');
  idleTimer=setTimeout(setIdle,IDLE_MS);
  setTimeout(function(){ $btn.style.animation='fcbFloat 3.5s ease-in-out infinite'; },50);
}

/* ═══════════════════════════════════════════════════════════
   ADMIN FLOATING INBOX VIEW
   ═══════════════════════════════════════════════════════════ */
function showAdminInboxView(){
  adminView='inbox';
  targetUid=null; targetUsername=null;
  stopPoll();
  if($ia) $ia.style.display='none';
  if($phBack) $phBack.classList.remove('on');
  var pt=document.getElementById('fcp-panel-title');
  if(pt) pt.textContent='PLAYER MESSAGES';
  if($st) $st.textContent='Inbox';

  $body.innerHTML=
    '<div class="fcw-inbox">'
    +'<div class="fcw-inbox-search"><input type="text" id="fcw-search" placeholder="Search players…" autocomplete="off" spellcheck="false"/></div>'
    +'<div class="fcw-conv-list" id="fcw-conv-list"><div class="pempty"><div class="pempty-ico">💬</div>Loading…</div></div>'
    +'</div>'
    +'<div class="fcw-inbox-footer">'
    +'<a href="<?= APP_URL ?>/admin/chat_inbox.php" class="fcw-inbox-full-link">📋 Open Full Inbox</a>'
    +'</div>';

  var searchEl=document.getElementById('fcw-search');
  if(searchEl){
    var st=null;
    searchEl.addEventListener('input',function(){
      clearTimeout(st);
      var q=searchEl.value.trim();
      if(!q){ renderAdminConvList(inboxConversations,false); return; }
      st=setTimeout(function(){ searchAdminPlayers(q); },280);
    });
  }

  loadAdminConversations();
  startInboxRefresh();
}

async function loadAdminConversations(silent){
  if(!silent){
    var cl=document.getElementById('fcw-conv-list');
    if(cl) cl.innerHTML='<div class="pempty"><div class="pempty-ico">💬</div>Loading…</div>';
  }
  try{
    var r=await fetch(API+'?action=conversations',{credentials:'same-origin'});
    if(!r.ok) throw new Error('HTTP '+r.status);
    var d=await r.json();
    if(!d.ok) throw new Error(d.error||'API error');
    // Already sorted by updated_at DESC from API — most recent first
    inboxConversations=d.conversations||[];
    var sq=document.getElementById('fcw-search');
    if(!sq||!sq.value.trim()){
      renderAdminConvList(inboxConversations,false);
    }
    // Update badge
    var totalUnread=inboxConversations.reduce(function(a,c){return a+(c.unread_count||0);},0);
    updBadge(totalUnread);
  }catch(e){
    var cl=document.getElementById('fcw-conv-list');
    if(cl&&adminView==='inbox') cl.innerHTML='<div class="pempty" style="color:var(--cw-danger)"><div class="pempty-ico">⚠️</div>Could not load inbox</div>';
  }
}

async function searchAdminPlayers(q){
  var cl=document.getElementById('fcw-conv-list');
  if(!cl) return;
  // Local filter first for snappiness
  var local=inboxConversations.filter(function(c){
    return (c.username||'').toLowerCase().indexOf(q.toLowerCase())!==-1
        || (c.full_name||'').toLowerCase().indexOf(q.toLowerCase())!==-1;
  });
  if(local.length) renderAdminConvList(local,false);

  try{
    var r=await fetch(API+'?action=search_players&q='+encodeURIComponent(q),{credentials:'same-origin'});
    if(!r.ok) return;
    var d=await r.json();
    if(d.ok) renderAdminConvList(d.players||[],true);
  }catch(e){}
}

function strToColor(str){
  var hash=0;
  for(var i=0;i<str.length;i++) hash=str.charCodeAt(i)+((hash<<5)-hash);
  var colors=['#00e5a0','#00b8ff','#ff6b35','#a78bfa','#f59e0b','#ec4899'];
  return colors[Math.abs(hash)%colors.length];
}

function renderAdminConvList(items,isSearch){
  var cl=document.getElementById('fcw-conv-list');
  if(!cl) return;
  if(!items||!items.length){
    cl.innerHTML='<div class="pempty"><div class="pempty-ico">'+(isSearch?'😕':'💬')+'</div>'+(isSearch?'No players found.':'No conversations yet.')+'</div>';
    return;
  }
  cl.innerHTML='';
  items.forEach(function(c){
    var unread=c.unread_count||0;
    var timeStr=c.last_time?(c.last_time.split(', ')[1]||c.last_time):'';
    var preview=c.last_message?esc(c.last_message):'<span style="opacity:.4;font-style:italic">No messages yet</span>';
    var col=strToColor(c.username||'');
    var initial=(c.username||'?').charAt(0).toUpperCase();

    var item=document.createElement('div');
    item.className='fcw-conv-item';
    item.innerHTML=
      '<div class="fcw-conv-avatar" style="background:'+col+'">'
      +(unread>0?'<div class="fcw-conv-unread-dot"></div>':'')
      +initial
      +'</div>'
      +'<div class="fcw-conv-info">'
      +'<div class="fcw-conv-name">'+esc(c.full_name||c.username)+'<span style="font-size:10px;color:var(--cw-muted);font-weight:400;margin-left:4px;">@'+esc(c.username)+'</span></div>'
      +'<div class="fcw-conv-preview">'+preview+'</div>'
      +'</div>'
      +'<div class="fcw-conv-meta">'
      +'<div class="fcw-conv-time">'+esc(timeStr)+'</div>'
      +(unread>0?'<div class="fcw-conv-badge">'+unread+'</div>':'')
      +'</div>';

    item.addEventListener('click',function(){
      openAdminConversation(c.user_id,c.username);
    });
    cl.appendChild(item);
  });
}

function startInboxRefresh(){
  stopInboxRefresh();
  inboxRefreshTimer=setInterval(function(){
    if(panelOpen&&adminView==='inbox') loadAdminConversations(true);
  },INBOX_REFRESH_MS);
}
function stopInboxRefresh(){
  if(inboxRefreshTimer){ clearInterval(inboxRefreshTimer); inboxRefreshTimer=null; }
}

/* ═══════════════════════════════════════════════════════════
   ADMIN: open a specific player conversation from bubble
   ═══════════════════════════════════════════════════════════ */
function openAdminConversation(uid,username){
  adminView='conversation';
  targetUid=uid; targetUsername=username;
  stopInboxRefresh();

  var pt=document.getElementById('fcp-panel-title');
  if(pt) pt.textContent='@'+username.toUpperCase();
  if($st) $st.textContent='Direct Message';
  if($phBack) $phBack.classList.add('on');

  $body.innerHTML=
    '<div class="pm" id="pm"><div class="pempty"><div class="pempty-ico">🏓</div>Loading…</div></div>'
    +'<div class="ptyp" id="ptyp"><span></span><span></span><span></span></div>';
  if($ia){ $ia.style.display='flex'; }
  if($txt){ $txt.value=''; $txt.style.height='auto'; $txt.focus(); }

  fetchMsgs(true);
  startPoll();
}

/* ═══════════════════════════════════════════════════════════
   BODY RENDER (player view)
   ═══════════════════════════════════════════════════════════ */
function renderBody(){
  if(!loggedIn){
    $body.innerHTML=
      '<div class="pg">'
      +'<div class="pg-ico">🏓</div>'
      +'<div class="pg-title">FALCON CHAT</div>'
      +'<div class="pg-sub">Register or log in to chat with our court team.</div>'
      +'<div class="pg-btns">'
      +'<a href="<?= APP_URL ?>/auth/register.php" class="pg-btn p">📝 Register Free</a>'
      +'<a href="<?= APP_URL ?>/auth/login.php" class="pg-btn s">🔑 Log In</a>'
      +'</div></div>';
    if($ia) $ia.style.display='none';
    return;
  }
  // Player: show their own conversation with admin (targetUid stays null)
  $body.innerHTML=
    '<div class="pm" id="pm"><div class="pempty"><div class="pempty-ico">🏓</div>Loading…</div></div>'
    +'<div class="ptyp" id="ptyp"><span></span><span></span><span></span></div>';
  if($ia) $ia.style.display='flex';
  if($txt){ $txt.focus(); }
  fetchMsgs(true);
}

/* ═══════════════════════════════════════════════════════════
   MESSAGES
   ═══════════════════════════════════════════════════════════ */
async function fetchMsgs(reset){
  if(reset) lastId=0;
  var qs=(isAdmin&&targetUid)
    ?'action=fetch&last_id=0&target_user_id='+targetUid
    :'action=fetch&last_id=0';
  try{
    var r=await fetch(API+'?'+qs,{credentials:'same-origin'});
    if(!r.ok) throw new Error('HTTP '+r.status);
    var d=await r.json();
    if(!d.ok) throw new Error(d.error||'API error');
    var list=document.getElementById('pm'); if(!list)return;
    if(!d.messages||!d.messages.length){
      list.innerHTML='<div class="pempty"><div class="pempty-ico">💬</div>No messages yet. Say hello! 👋</div>';
      return;
    }
    list.innerHTML=''; resetDates();
    d.messages.forEach(function(m){ appendMsg(m,list); });
    lastId=Math.max.apply(null,[lastId].concat(d.messages.map(function(m){return m.id;})));
    scrollBot(list);
  }catch(e){
    var list=document.getElementById('pm');
    if(list) list.innerHTML='<div class="pempty"><div class="pempty-ico">⚠️</div>Could not load messages.<br><small style="opacity:.6">'+esc(e.message)+'</small></div>';
  }
}

function startPoll(){ stopPoll(); pollTimer=setInterval(doPoll,POLL_MS); }
function stopPoll() { if(pollTimer){clearInterval(pollTimer);pollTimer=null;} }

async function doPoll(){
  if(!panelOpen||!loggedIn) return;
  // Admin in inbox view: don't poll messages, let inbox refresh handle it
  if(isAdmin&&adminView==='inbox') return;
  var qs=(isAdmin&&targetUid)
    ?'action=poll&last_id='+lastId+'&target_user_id='+targetUid
    :'action=poll&last_id='+lastId;
  try{
    var r=await fetch(API+'?'+qs,{credentials:'same-origin'}); var d=await r.json(); if(!d.ok)return;
    var list=document.getElementById('pm'); if(!list)return;
    if(d.messages&&d.messages.length){
      var e=list.querySelector('.pempty'); if(e)e.remove();
      d.messages.forEach(function(m){ appendMsg(m,list); });
      lastId=Math.max.apply(null,[lastId].concat(d.messages.map(function(m){return m.id;})));
      scrollBot(list);
    }
    if(d.unread!==undefined) updBadge(d.unread);
  }catch(e){}
}

async function pollBadge(){
  if(!loggedIn) return;
  try{
    var r=await fetch(API+'?action=unread_count',{credentials:'same-origin'});
    var d=await r.json(); if(d.ok) updBadge(d.count);
  }catch(e){}
}

function updBadge(n){
  if($badge){
    if(n>0&&!panelOpen){ $badge.textContent=n>99?'99+':n; $badge.classList.add('on'); }
    else $badge.classList.remove('on');
  }
  document.querySelectorAll('.fcp-nav-badge').forEach(function(el){
    if(n>0){ el.textContent=n>99?'99+':n; el.classList.add('show'); }
    else el.classList.remove('show');
  });
}

/* ─── Message rendering ──────────────────────────────────── */
function fmtTime(ts){
  var d=new Date(ts*1000),h=d.getHours(),m=String(d.getMinutes()).padStart(2,'0');
  var ap=h>=12?'PM':'AM'; h=h%12||12; return h+':'+m+' '+ap;
}
function fmtDate(ts){
  var d=new Date(ts*1000),t=new Date(),y=new Date();
  y.setDate(y.getDate()-1);
  if(d.toDateString()===t.toDateString()) return 'Today';
  if(d.toDateString()===y.toDateString()) return 'Yesterday';
  return d.toLocaleDateString(undefined,{month:'long',day:'numeric',year:'numeric'});
}
function dateKey(ts){ var d=new Date(ts*1000); return d.getFullYear()+'-'+d.getMonth()+'-'+d.getDate(); }
var _ld=null; function resetDates(){ _ld=null; }

function appendMsg(msg,container){
  var dk=msg.timestamp?dateKey(msg.timestamp):(msg.date||'');
  if(dk&&dk!==_ld){
    _ld=dk;
    var dv=document.createElement('div');
    dv.className='pdate';
    dv.textContent=msg.timestamp?fmtDate(msg.timestamp):(msg.date_label||dk);
    container.appendChild(dv);
  }
  var div=document.createElement('div');
  div.className='msg '+(msg.mine?'me':'you');
  var sl=msg.is_admin_sender
    ?'<span class="admin-lbl">🛡 Admin</span>'
    :'<span>'+esc(msg.sender)+'</span>';
  var dt=msg.timestamp?fmtTime(msg.timestamp):(msg.time||'');
  div.innerHTML=
    '<div class="msg-bbl">'+esc(msg.text)+'</div>'
    +'<div class="msg-meta">'
    +(!msg.mine?sl+' · ':'')
    +'<span>'+dt+'</span>'
    +(msg.mine?' · <span style="opacity:.65">'+(msg.is_read?'✓✓':'✓')+'</span>':'')
    +'</div>';
  container.appendChild(div);
}

function scrollBot(el){
  var far=el.scrollHeight-el.scrollTop-el.clientHeight>300;
  if(far) el.scrollTop=el.scrollHeight;
  else el.scrollTo({top:el.scrollHeight,behavior:'smooth'});
}

/* ─── Send ───────────────────────────────────────────────── */
async function send(){
  if(sending||!$txt) return;
  var text=$txt.value.trim(); if(!text) return;

  // Guard: admin must have a target player selected — never self-send
  if(isAdmin && !targetUid){
    var cl=document.getElementById('pm');
    if(cl){
      var err=document.createElement('div');
      err.style.cssText='text-align:center;color:var(--cw-danger);font-size:12px;padding:4px 8px;';
      err.textContent='⚠️ Please select a player conversation first.';
      cl.appendChild(err);
      setTimeout(function(){ if(err.parentNode) err.parentNode.removeChild(err); },3000);
    }
    return;
  }

  sending=true; $snd.disabled=true;
  $txt.value=''; $txt.style.height='auto';
  var body={message:text};
  if(isAdmin&&targetUid) body.target_user_id=targetUid;
  try{
    var r=await fetch(API+'?action=send',{
      method:'POST',
      credentials:'same-origin',
      headers:{'Content-Type':'application/json'},
      body:JSON.stringify(body)
    });
    var d=await r.json();
    if(!d.ok){
      console.error('Send:',d.error);
      var list=document.getElementById('pm');
      if(list){
        var err=document.createElement('div');
        err.style.cssText='text-align:center;color:var(--cw-danger);font-size:12px;padding:4px 8px;';
        err.textContent = d.error === 'Not authenticated'
            ? '⚠️ Session expired — please refresh the page'
            : '⚠️ Message failed to send. Try again.';
        list.appendChild(err);
        setTimeout(function(){ if(err.parentNode) err.parentNode.removeChild(err); }, 4000);
      }
      return;
    }
    var list=document.getElementById('pm');
    if(list){
      var e=list.querySelector('.pempty'); if(e)e.remove();
      appendMsg(d.message,list);
      lastId=Math.max(lastId,d.message.id);
      scrollBot(list);
    }
  }catch(e){console.error('Chat:',e);}
  finally{ sending=false; $snd.disabled=false; if($txt)$txt.focus(); }
}

function handleKey(e){ if(e.key==='Enter'&&!e.shiftKey){e.preventDefault();send();} }
function resize(el){ el.style.height='auto'; el.style.height=Math.min(el.scrollHeight,90)+'px'; }
function toggleEmoji(){ emojiOpen=!emojiOpen; $ep.classList.toggle('on',emojiOpen); }
function insertEmoji(e){
  if(!$txt) return;
  var s=$txt.selectionStart, en=$txt.selectionEnd;
  $txt.value=$txt.value.slice(0,s)+e+$txt.value.slice(en);
  $txt.focus(); $txt.setSelectionRange(s+e.length,s+e.length);
  if(emojiOpen) toggleEmoji();
}
function esc(str){
  return String(str||'')
    .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
    .replace(/"/g,'&quot;').replace(/'/g,'&#39;');
}

/* ─── Admin inbox embed (for chat_inbox.php) ─────────────── */
// Uses its own inboxOpen flag — does NOT set panelOpen=true
function mountInbox(el,uid,username){
  if(!el) return;
  stopPoll();
  inboxOpen = true;
  targetUid = uid;

  var ibxLastId = 0;
  var ibxPollTimer = null;

  function ibxStopPoll(){
    if(ibxPollTimer){ clearInterval(ibxPollTimer); ibxPollTimer=null; }
  }

  el.innerHTML=
    '<div class="ph" style="flex-shrink:0;">'
    +'<button class="ph-back on" onclick="backToList()" title="Back">◀</button>'
    +'<div class="ph-ico"><span style="font-size:15px;">👤</span></div>'
    +'<div class="ph-inf"><div class="ph-title">@'+esc(username)+'</div>'
    +'<div class="ph-sub"><span class="ph-dot"></span>Direct Message</div></div></div>'
    +'<div class="pm" id="ibx-pm" style="flex:1;overflow-y:auto;-webkit-overflow-scrolling:touch;min-height:0;"></div>'
    +'<div class="ptyp" id="ibx-ty"><span></span><span></span><span></span></div>'
    +'<div class="pep" id="ibx-ep" style="position:relative;bottom:unset;max-height:110px;"></div>'
    +'<div class="pia" style="flex-shrink:0;">'
    +'<div class="piw">'
    +'<button class="pibtn" onclick="ibxET()" title="Emoji">😊</button>'
    +'<textarea class="pitxt" id="ibx-txt" placeholder="Type a message…" rows="1"'
    +' onkeydown="ibxKey(event)" oninput="FC.resize(this)"></textarea>'
    +'</div>'
    +'<button class="psend" id="ibx-snd" onclick="ibxSend()">'
    +'<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">'
    +'<line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/>'
    +'</svg></button></div>';

  var iep=document.getElementById('ibx-ep');
  if(iep){
    EMOJIS.forEach(function(e){
      var b=document.createElement('button'); b.className='pepi'; b.textContent=e; b.type='button';
      b.onclick=function(){
        var inp=document.getElementById('ibx-txt'); if(!inp)return;
        var s=inp.selectionStart,en=inp.selectionEnd;
        inp.value=inp.value.slice(0,s)+e+inp.value.slice(en);
        inp.focus(); inp.setSelectionRange(s+e.length,s+e.length);
        iep.classList.remove('on');
      };
      iep.appendChild(b);
    });
  }

  window.ibxET=function(){ if(iep) iep.classList.toggle('on'); };
  window.ibxKey=function(ev){ if(ev.key==='Enter'&&!ev.shiftKey){ev.preventDefault();ibxSend();} };

  window.ibxSend=async function(){
    var inp=document.getElementById('ibx-txt'),sb=document.getElementById('ibx-snd');
    if(!inp) return;
    var text=inp.value.trim(); if(!text)return;
    if(sb) sb.disabled=true;
    inp.value=''; inp.style.height='auto';
    try{
      var r=await fetch(API+'?action=send',{
        method:'POST',
        credentials:'same-origin',
        headers:{'Content-Type':'application/json'},
        body:JSON.stringify({message:text,target_user_id:uid})
      });
      var d=await r.json();
      if(!d.ok){ console.error('Ibx send error:',d.error); return; }
      var list=document.getElementById('ibx-pm');
      if(list){
        var e=list.querySelector('.pempty'); if(e)e.remove();
        appendMsg(d.message,list);
        ibxLastId=Math.max(ibxLastId,d.message.id);
        scrollBot(list);
      }
    }catch(e){ console.error('Ibx send exception:',e); }
    finally{
      if(sb) sb.disabled=false;
      var inp2=document.getElementById('ibx-txt');
      if(inp2) inp2.focus();
    }
  };

  // Load initial messages
  (async function(){
    var list=document.getElementById('ibx-pm');
    if(!list) return;
    list.innerHTML='<div class="pempty"><div class="pempty-ico">🏓</div>Loading…</div>';
    try{
      var r=await fetch(API+'?action=fetch&last_id=0&target_user_id='+uid,{credentials:'same-origin'});
      if(!r.ok) throw new Error('HTTP '+r.status);
      var d=await r.json();
      if(!d.ok) throw new Error(d.error||'API error');
      if(!d.messages||!d.messages.length){
        list.innerHTML='<div class="pempty"><div class="pempty-ico">💬</div>No messages yet.</div>';
      } else {
        list.innerHTML=''; resetDates();
        d.messages.forEach(function(m){ appendMsg(m,list); });
        ibxLastId=Math.max.apply(null,[0].concat(d.messages.map(function(m){return m.id;})));
        scrollBot(list);
      }
    }catch(e){
      var list2=document.getElementById('ibx-pm');
      if(list2) list2.innerHTML='<div class="pempty"><div class="pempty-ico">⚠️</div>Could not load messages.<br><small style="opacity:.6">'+esc(e.message)+'</small></div>';
    }
  }());

  ibxStopPoll();
  ibxPollTimer=setInterval(async function(){
    try{
      var r=await fetch(API+'?action=poll&last_id='+ibxLastId+'&target_user_id='+uid,{credentials:'same-origin'});
      if(!r.ok) return;
      var d=await r.json();
      if(!d.ok||!d.messages||!d.messages.length) return;
      var list=document.getElementById('ibx-pm'); if(!list)return;
      var e=list.querySelector('.pempty'); if(e)e.remove();
      d.messages.forEach(function(m){ appendMsg(m,list); });
      ibxLastId=Math.max.apply(null,[ibxLastId].concat(d.messages.map(function(m){return m.id;})));
      scrollBot(list);
    }catch(e){}
  },POLL_MS);

  el._ibxCleanup = function(){ ibxStopPoll(); inboxOpen=false; };

  var it=document.getElementById('ibx-txt'); if(it)it.focus();
}

/* ─── Global listeners ───────────────────────────────────── */
document.addEventListener('click',function(e){
  if(emojiOpen&&!e.target.closest('.pibtn')&&!e.target.closest('.pep')){
    $ep.classList.remove('on'); emojiOpen=false;
  }
});
document.addEventListener('keydown',function(e){
  if(e.key==='Escape'&&panelOpen){
    if(isAdmin&&adminView==='conversation'){ showAdminInboxView(); }
    else { closePanel(); }
  }
});

/* ─── Public API ─────────────────────────────────────────── */
window.FC=window.falconChat={
  init:init,
  toggleBubble:function(){ panelOpen?closePanel():openPanel(); },
  openPanel:openPanel,
  close:closePanel,
  send:send,
  handleKey:handleKey,
  resize:resize,
  toggleEmoji:toggleEmoji,
  mountInbox:mountInbox,
  dismiss:dismiss,
  restore:restore,
  isReady:function(){ return ready; },
};

if(document.readyState==='loading'){
  document.addEventListener('DOMContentLoaded',init);
} else {
  init();
}
}());
</script>
<?php /* end active widget */ ?>