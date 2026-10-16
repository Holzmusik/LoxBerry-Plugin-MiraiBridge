/*!
 * (C) Ionic http://ionicframework.com - MIT License
 */
import{e as o,w as s}from"./p-D9OR05yj.js";import{a as t,s as r}from"./p-DNxEB9-T.js";import{c as a}from"./p-B4xjg_Qu.js";import"./p-Dojwmvde.js";const e=()=>{const e=window;e.addEventListener("statusTap",(()=>{o((()=>{const o=document.elementFromPoint(e.innerWidth/2,e.innerHeight/2);if(!o)return;const m=t(o);m&&new Promise((o=>a(m,o))).then((()=>{s((async()=>{m.style.setProperty("--overflow","hidden"),await r(m,300),m.style.removeProperty("--overflow")}))}))}))}))};export{e as startStatusTap}