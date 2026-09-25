package controller

// Cosmetic removal only; the bridge AdminGate enforces access on the server.
const publicEntryRemoval = `<style id="probe-public-only">a[href*="/dashboard"],a[href*="/login"]{display:none!important}</style><script>(()=>{const clean=()=>document.querySelectorAll('a[href*="/dashboard"],a[href*="/login"]').forEach(a=>a.remove());new MutationObserver(clean).observe(document.documentElement,{childList:true,subtree:true});clean();})();</script>`
