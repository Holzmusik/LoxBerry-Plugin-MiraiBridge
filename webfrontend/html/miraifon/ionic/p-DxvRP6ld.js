/*!
 * (C) Ionic http://ionicframework.com - MIT License
 */
import{g as o}from"./p-D5akOqD0.js";var n,t;!function(o){o.Unimplemented="UNIMPLEMENTED",o.Unavailable="UNAVAILABLE"}(n||(n={})),function(o){o.Body="body",o.Ionic="ionic",o.Native="native",o.None="none"}(t||(t={}));const e={getEngine(){const n=o();if(n?.isPluginAvailable("Keyboard"))return n.Plugins.Keyboard},getResizeMode(){const o=this.getEngine();return o?.getResizeMode?o.getResizeMode().catch((o=>{if(o.code!==n.Unimplemented)throw o})):Promise.resolve(void 0)}};export{e as K,t as a}