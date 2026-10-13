(function(){
    'use strict';
    var serverSaveTimer=null;

    function setTournamentSelection(value){
        document.querySelectorAll('input[name="ToIds[]"]').forEach(function(cb){cb.checked=value;});
    }
    function setNamedSelection(name,value){
        document.querySelectorAll('input[name="'+name+'"]:not(:disabled)').forEach(function(cb){cb.checked=value;});
    }
    function updateMode(select){
        var targetId=select.getAttribute('data-target');
        var target=targetId?document.getElementById(targetId):null;
        if(!target)return;
        target.classList.toggle('resultspack-hidden',select.value!=='specific');
    }
    function updateIndividualSource(){
        var selected=document.querySelector('input[name="individual_source"]:checked');
        var row=document.getElementById('resultspack-individual-event-row');
        if(!row)return;
        row.classList.toggle('resultspack-hidden',!selected||selected.value!=='events');
    }
    function updateRecordStatus(){
        var selected=document.querySelector('input[name="record_status"]:checked');
        var status=selected?selected.value:'none';
        var box=document.getElementById('resultspack-record-qualifiers');
        if(!box)return;
        box.classList.remove('resultspack-hidden');
        box.querySelectorAll('[data-record-status]').forEach(function(label){
            var rule=label.getAttribute('data-record-status')||'all';
            var allowed=rule.split(/\s+/);
            var show=rule==='all'||allowed.indexOf(status)!==-1;
            label.classList.toggle('resultspack-hidden',!show);
            var input=label.querySelector('input');
            if(input)input.disabled=!show;
        });
    }
    function updateEventNameLink(){
        var form=document.getElementById('resultspack-generator');
        if(!form)return;
        var same=form.elements['event_name_same_as_cover'];
        var cover=form.elements['cover_title'];
        var eventName=form.elements['event_name'];
        var wrap=document.getElementById('resultspack-event-name-wrap');
        if(!same||!cover||!eventName)return;
        if(same.checked){
            eventName.value=cover.value;
            eventName.disabled=true;
            if(wrap)wrap.classList.add('resultspack-linked-field');
        }else{
            eventName.disabled=false;
            if(wrap)wrap.classList.remove('resultspack-linked-field');
        }
    }
    function applyDateFormat(){
        var selected=document.querySelector('input[name="date_format"]:checked');
        var field=document.getElementById('resultspack-event-date');
        if(!selected||!field)return;
        var attr=selected.value==='long'?'data-long-date':'data-short-date';
        var value=field.getAttribute(attr);
        if(value!==null)field.value=value;
    }

    function autoGrowTextarea(textarea){
        if(!textarea||!textarea.classList||!textarea.classList.contains('resultspack-autogrow'))return;
        textarea.style.height='auto';
        textarea.style.height=Math.max(28,textarea.scrollHeight)+'px';
    }
    function resizeAllAutoGrow(){
        document.querySelectorAll('textarea.resultspack-autogrow').forEach(autoGrowTextarea);
    }

    function updateWeather(){
        var indoor=document.getElementById('resultspack-weather-indoor');
        var noData=document.getElementById('resultspack-weather-indoor-na');
        var noDataWrap=document.getElementById('resultspack-weather-indoor-na-wrap');
        var environment=document.querySelector('.resultspack-outdoor-weather');

        if(!indoor||!environment)return;

        //The na choice only makes sense for an indoor event
        if(noDataWrap){
            noDataWrap.style.display=indoor.checked?'':'none';
        }

        //If the event is changed back to outdoor, indoors gets deactivated
        if(!indoor.checked&&noData){
            noData.checked=false;
        }

        //Being indoors shouldn'tr disable environmental data, only choosing the option does that
        var disabled=!!(indoor.checked&&noData&&noData.checked);

        environment.classList.toggle('resultspack-disabled',disabled);

        environment.querySelectorAll('input,select,textarea,button').forEach(function(control){
            control.disabled=disabled;
        });

        //Sunny / rain / snow etc. are outdoor descriptions, so hide them indoors.
        var conditions=environment.querySelector('.resultspack-weather-conditions');

        if(conditions){
            conditions.style.display=indoor.checked?'none':'';

            conditions.querySelectorAll('input').forEach(function(control){
                control.disabled=indoor.checked||disabled;
            });
        }
    }

    function rememberBaseDisabled(control){
        if(control.getAttribute('data-resultspack-base-disabled')===null){
            control.setAttribute('data-resultspack-base-disabled',control.disabled?'1':'0');
        }
    }
    function dependencyBadge(container){
        var host=container;
        if(container.tagName&&container.tagName.toLowerCase()==='tr'){
            host=container.querySelector('td:last-child,th:last-child')||container;
        }
        var badge=null;
        Array.prototype.some.call(host.children||[],function(child){
            if(child.classList&&child.classList.contains('resultspack-not-included-badge')){badge=child;return true;}
            return false;
        });
        if(!badge){
            badge=document.createElement('span');
            badge.className='resultspack-not-included-badge';
            badge.textContent='NOT INCLUDED IN PDF';
            host.insertBefore(badge,host.firstChild);
        }
        return badge;
    }
    function paintDependentContainer(container,disabled){
        var targets=[];
        if(container.tagName&&container.tagName.toLowerCase()==='tr'){
            targets=Array.prototype.slice.call(container.children||[]);
        }else{
            targets=[container];
        }
        targets.forEach(function(target){
            if(!target||!target.style)return;
            if(disabled){
                target.style.setProperty('background','#dedede','important');
                target.style.setProperty('color','#666','important');
                target.style.setProperty('filter','grayscale(1)','important');
            }else{
                target.style.removeProperty('background');
                target.style.removeProperty('color');
                target.style.removeProperty('filter');
            }
        });
    }
    function setDependentDisabled(container,disabled){
        container.classList.toggle('resultspack-section-disabled',disabled);
        container.setAttribute('aria-disabled',disabled?'true':'false');
        paintDependentContainer(container,disabled);
        var badge=dependencyBadge(container);
        badge.style.display=disabled?'inline-block':'none';
        container.querySelectorAll('input,select,textarea,button').forEach(function(control){
            rememberBaseDisabled(control);
            control.disabled=disabled||control.getAttribute('data-resultspack-base-disabled')==='1';
            if(disabled){
                control.style.setProperty('opacity','.45','important');
                control.style.setProperty('cursor','not-allowed','important');
            }else{
                control.style.removeProperty('opacity');
                control.style.removeProperty('cursor');
            }
        });
    }
    function updateSectionDependencies(){
        var form=document.getElementById('resultspack-generator');
        document.querySelectorAll('[data-depends-section]').forEach(function(container){
            var name=container.getAttribute('data-depends-section');
            var master=form&&form.elements[name]?form.elements[name]:document.querySelector('input[name="'+name+'"]');
            setDependentDisabled(container,!master||!master.checked);
        });
    }

    function updateDnsCoverDependency(){
        var form=document.getElementById('resultspack-generator');
        document.querySelectorAll('[data-depends-dns]').forEach(function(container){
            var name=container.getAttribute('data-depends-dns');
            var master=form&&form.elements[name]?form.elements[name]:document.querySelector('input[name="'+name+'"]');
            var includeIndividuals=form&&form.elements['include_individuals'];
            var enabled=!!(master&&master.checked&&(!includeIndividuals||includeIndividuals.checked));
            setDependentDisabled(container,!enabled);
        });
    }

    function serialiseForm(form){
        var data={};
        var done={};
        Array.prototype.forEach.call(form.elements,function(el){
            if(!el.name||el.type==='submit'||el.type==='button'||el.type==='hidden'||el.type==='file'||el.getAttribute('data-resultspack-no-save')==='1')return;
            if(done[el.name])return;
            done[el.name]=true;
            var controls=form.querySelectorAll('[name="'+el.name.replace(/"/g,'\\"')+'"]');
            if(el.type==='radio'){
                var checked=Array.prototype.find.call(controls,function(item){return item.checked;});
                data[el.name]={type:'radio',value:checked?checked.value:''};
            }else if(el.type==='checkbox'&&/\[\]$/.test(el.name)){
                data[el.name]={type:'check-array',value:Array.prototype.filter.call(controls,function(item){return item.checked;}).map(function(item){return item.value;})};
            }else if(el.type==='checkbox'){
                data[el.name]={type:'checkbox',value:!!el.checked};
            }else{
                data[el.name]={type:'value',value:el.value};
            }
        });
        return data;
    }

    // Only one settings request runs at a time; later edits replace queued ones.
    var settingsPending=null;
    var settingsActive=null;
    var settingsResetting=false;
    var settingsLocalSaved=false;
    var settingsLastSaved=null;

    function settingsMessage(form,text,error){
        var status=form.querySelector('[data-settings-status]');
        if(status){
            status.textContent=text;
            status.style.color=error?'#a12622':'#245b2a';
        }
    }

    async function settingsRequest(form,action,payload){
        var body=new URLSearchParams();
        body.set('key',form.getAttribute('data-settings-key')||'');
        body.set('csrf_token',form.elements['csrf_token'].value);
        body.set('generation',form.elements['settings_generation']?form.elements['settings_generation'].value:'');
        body.set('action',action);
        if(action==='save')body.set('payload',payload);
        var controller=new AbortController();
        var timeout=window.setTimeout(function(){controller.abort();},15000);
        try{
            var response=await fetch('SaveSettings.php',{
                method:'POST',
                credentials:'same-origin',
                cache:'no-store',
                body:body,
                signal:controller.signal
            });
            var data;
            try{data=await response.json();}
            catch(error){throw new Error('The server returned an unexpected response (HTTP '+response.status+'). Check that IANSEO is running and your session is still active.');}
            if(!response.ok||!data.ok)throw new Error(data.error||'The server did not confirm the save (HTTP '+response.status+').');
            return data;
        }catch(error){
            if(error.name==='AbortError')throw new Error('The server did not confirm within 15 seconds. Check the connection and try again.');
            if(error instanceof TypeError)throw new Error('Cannot reach IANSEO. Check your network connection and that the server is running.');
            throw error;
        }finally{window.clearTimeout(timeout);}
    }

    function drainSettings(){
        if(settingsActive||settingsResetting||!settingsPending)return;
        var job=settingsPending;
        settingsPending=null;
        settingsMessage(job.form,'Saving to IANSEO…',false);
        settingsActive=settingsRequest(job.form,'save',job.payload)
            .then(function(){
                settingsLastSaved=job.payload;
                if(!settingsResetting&&!settingsPending&&JSON.stringify({version:2,fields:serialiseForm(job.form)})===job.payload){
                    settingsMessage(job.form,settingsLocalSaved
                        ?'Saved to IANSEO and this browser.'
                        :'Saved to IANSEO. Browser storage is unavailable or full.',false);
                }
            })
            .catch(function(error){
                if(!settingsResetting&&!settingsPending&&JSON.stringify({version:2,fields:serialiseForm(job.form)})===job.payload){
                    settingsMessage(job.form,'Could not confirm saving to IANSEO. '+error.message+' '+(settingsLocalSaved
                        ?'A browser copy was saved, but reloading may restore older server settings. Keep this page open or copy your text.'
                        :'No browser backup was saved either. Keep this page open and copy your text.'),true);
                }
            })
            .finally(function(){
                settingsActive=null;
                if(settingsPending&&!settingsResetting)drainSettings();
            });
    }

    function persistServer(form,payload,immediate){
        if(settingsResetting||!form.getAttribute('data-settings-key'))return;
        if(serverSaveTimer)window.clearTimeout(serverSaveTimer);
        settingsPending={form:form,payload:payload};
        if(immediate){drainSettings();}
        else{serverSaveTimer=window.setTimeout(drainSettings,350);}
    }

    function saveForm(form,key,immediate){
        if(settingsResetting)return;
        var payload=JSON.stringify({version:2,fields:serialiseForm(form)});
        settingsLocalSaved=false;
        try{localStorage.setItem(key,payload);settingsLocalSaved=true;}catch(error){}
        // Match the server's existing limit using UTF-8 bytes, not characters.
        if(new Blob([payload]).size>262144){
            settingsPending=null;
            if(serverSaveTimer)window.clearTimeout(serverSaveTimer);
            settingsMessage(form,'Could not save to IANSEO: this form exceeds the 256 KiB limit. Shorten large pasted text or use Advanced → Reset this form. '+(settingsLocalSaved?'A browser copy was saved; do not rely on a reload to recover it.':'Browser storage also failed; keep this page open and copy any text you need.'),true);
            return;
        }
        settingsMessage(form,'Changes waiting to be saved…',false);
        persistServer(form,payload,!!immediate);
    }

    function installSettingsControls(form,key){
        var panel=document.createElement('div');
        panel.style.cssText='padding:10px;margin:10px 0;border:1px solid #ccc;';
        panel.innerHTML='<p data-settings-status role="status" aria-live="polite">Changes are saved automatically.</p>'
            +'<button type="button" class="Button" data-settings-retry>Save now / Retry</button>'
            +'<details style="margin-top:12px"><summary>Advanced</summary>'
            +'<p>Reset this results-pack form to its initial defaults for the current competition selection. '
            +'This discards saved text and selections, including award recipients. '
            +'Competition results, weather records and custom-award definitions are kept. '
            +'Other users of this same competition selection will see the reset settings when they reload.</p>'
            +'<button type="button" class="Button" data-settings-reset>Reset this form</button></details>';
        form.appendChild(panel);
        panel.querySelector('[data-settings-retry]').addEventListener('click',function(){saveForm(form,key,true);});
        panel.querySelector('[data-settings-reset]').addEventListener('click',async function(){
            if(settingsResetting)return;
            if(!window.confirm('Discard the saved text and selections for this results-pack form and start again with defaults? Competition data and award definitions will be kept.'))return;
            settingsResetting=true;
            settingsPending=null;
            if(serverSaveTimer)window.clearTimeout(serverSaveTimer);
            var disabled=Array.from(form.elements).map(function(control){return [control,control.disabled];});
            disabled.forEach(function(pair){pair[0].disabled=true;});
            settingsMessage(form,'Resetting this form…',false);
            try{
                // Let an already-started save finish before sending the reset.
                if(settingsActive)await settingsActive;
                var result=await settingsRequest(form,'reset','');
                if(form.elements['settings_generation'])form.elements['settings_generation'].value=String(result.generation);
                try{
                    localStorage.setItem(key,JSON.stringify({version:2,fields:{}}));
                    var legacy=form.getAttribute('data-legacy-storage-key');
                    if(legacy)localStorage.removeItem(legacy);
                }catch(error){}
                // Prevent pagehide from saving the discarded values on reload.
                window.location.reload();
            }catch(error){
                settingsResetting=false;
                disabled.forEach(function(pair){pair[0].disabled=pair[1];});
                settingsMessage(form,'The reset could not be confirmed. '+error.message+' No reload was performed; your current text is still visible.',true);
            }
        });
        window.addEventListener('beforeunload',function(event){
            if(settingsResetting)return;
            var payload=JSON.stringify({version:2,fields:serialiseForm(form)});
            if(payload!==settingsLastSaved){event.preventDefault();event.returnValue='';}
        });
    }

    function restoreLegacyForm(form,saved){
        Object.keys(saved||{}).forEach(function(name){
            if(name==='weather_conditions'&&Array.isArray(saved[name])){
                form.querySelectorAll('input[name="weather_conditions[]"]').forEach(function(el){el.checked=saved[name].indexOf(el.value)!==-1;});
                return;
            }
            var controls=form.querySelectorAll('[name="'+name.replace(/"/g,'\\"')+'"]');
            if(!controls.length)return;
            if(controls[0].getAttribute('data-resultspack-no-save')==='1')return;
            if(controls[0].type==='radio'){
                controls.forEach(function(el){el.checked=String(el.value)===String(saved[name]);});
            }else if(controls[0].type==='checkbox'){
                controls[0].checked=!!saved[name];
            }else{
                controls[0].value=saved[name];
            }
        });
    }

    function applySaved(form,saved){
        if(!saved)return false;
        if(saved.version!==2||!saved.fields){
            restoreLegacyForm(form,saved||{});
            return true;
        }
        Object.keys(saved.fields).forEach(function(name){
            var state=saved.fields[name];
            var controls=form.querySelectorAll('[name="'+name.replace(/"/g,'\\"')+'"]');
            if(!controls.length||!state)return;
            if(controls[0].getAttribute('data-resultspack-no-save')==='1')return;
            if(state.type==='radio'){
                controls.forEach(function(el){if(!el.disabled)el.checked=String(el.value)===String(state.value);});
            }else if(state.type==='check-array'){
                controls.forEach(function(el){if(!el.disabled)el.checked=state.value.indexOf(el.value)!==-1;});
            }else if(state.type==='checkbox'){
                if(!controls[0].disabled)controls[0].checked=!!state.value;
            }else if(state.type==='value'){
                if(!controls[0].disabled)controls[0].value=state.value;
            }
        });
        return true;
    }

    function decodeServerSettings(form){
        var encoded=form.getAttribute('data-server-settings')||'';
        if(!encoded)return null;
        try{
            var binary=atob(encoded);
            var bytes=new Uint8Array(binary.length);
            for(var i=0;i<binary.length;i++)bytes[i]=binary.charCodeAt(i);
            var json=(typeof TextDecoder!=='undefined')?new TextDecoder('utf-8').decode(bytes):binary;
            return JSON.parse(json);
        }catch(e){return null;}
    }

    function restoreForm(form,key){
        var serverSaved=decodeServerSettings(form);
        if(serverSaved&&applySaved(form,serverSaved))return true;
        var legacyKey=form.getAttribute('data-legacy-storage-key')||'';
        var keys=[key];
        if(legacyKey&&legacyKey!==key)keys.push(legacyKey);
        for(var i=0;i<keys.length;i++){
            var raw=null;
            try{raw=localStorage.getItem(keys[i]);}catch(e){}
            if(!raw)continue;
            try{
                var restored=applySaved(form,JSON.parse(raw||'{}'));
                if(restored&&keys[i]!==key){try{localStorage.setItem(key,raw);}catch(e){}}
                if(restored)return true;
            }catch(e){}
        }
        return false;
    }

    function updateCustomAwardsVisibility(){
        var form=document.getElementById('resultspack-generator');
        var master=form&&form.elements['include_custom_awards'];
        var body=document.getElementById('resultspack-custom-awards-body');
        if(body)body.style.display=(!master||master.checked)?'':'none';
    }

    function updateAwardGroupVisibility(){
        var library=document.getElementById('resultspack-award-library');
        if(!library)return;
        var includeGroup=true;
        Array.prototype.forEach.call(library.children,function(item){
            if(item.classList&&item.classList.contains('resultspack-award-divider')){
                var toggle=item.querySelector('input[name^="custom_award_group_include_"]');
                includeGroup=!toggle||toggle.checked;
                item.classList.toggle('resultspack-award-group-disabled',!includeGroup);
            }else if(item.classList&&item.classList.contains('resultspack-award-row')){
                item.classList.toggle('resultspack-award-group-disabled',!includeGroup);
            }
        });
    }

    function refreshUi(){
        document.querySelectorAll('.resultspack-mode-select').forEach(updateMode);
        updateIndividualSource();
        updateRecordStatus();
        updateWeather();
        updateSectionDependencies();
        updateDnsCoverDependency();
        updateEventNameLink();
        updateCustomAwardsVisibility();
        updateAwardGroupVisibility();
    }

    function captureAwardEditorState(container){
        var state={};
        if(!container)return state;
        container.querySelectorAll('input,select,textarea').forEach(function(control){
            if(!control.name)return;
            if(control.type==='checkbox'||control.type==='radio'){
                state[control.name]={checked:!!control.checked};
            }else{
                state[control.name]={value:control.value};
            }
        });
        return state;
    }

    function restoreAwardEditorState(container,state){
        if(!container)return;
        Object.keys(state||{}).forEach(function(name){
            var control=container.querySelector('[name="'+name.replace(/"/g,'\\"')+'"]');
            if(!control)return;
            if(Object.prototype.hasOwnProperty.call(state[name],'checked'))control.checked=!!state[name].checked;
            if(Object.prototype.hasOwnProperty.call(state[name],'value'))control.value=state[name].value;
        });
    }

    function findAwardRow(container,key){
        if(!container||!key)return null;
        var rows=container.querySelectorAll('[data-award-key]');
        for(var i=0;i<rows.length;i++){
            if(rows[i].getAttribute('data-award-key')===key)return rows[i];
        }
        return null;
    }

    function focusWithoutScrolling(control){
        if(!control||!control.focus)return;
        try{control.focus({preventScroll:true});}
        catch(e){
            var x=window.pageXOffset||document.documentElement.scrollLeft||0;
            var y=window.pageYOffset||document.documentElement.scrollTop||0;
            control.focus();
            window.scrollTo(x,y);
        }
    }

    function refreshAwardLibrary(preferredKey,action,direction,kind){
        var current=document.getElementById('resultspack-award-library');
        if(!current)return Promise.resolve();
        var savedState=captureAwardEditorState(current);
        var scrollX=window.pageXOffset||document.documentElement.scrollLeft||0;
        var scrollY=window.pageYOffset||document.documentElement.scrollTop||0;
        var url=window.location.pathname+window.location.search;
        return fetch(url,{method:'GET',credentials:'same-origin',cache:'no-store',headers:{'X-Requested-With':'XMLHttpRequest'}})
            .then(function(response){if(!response.ok)throw new Error('Could not refresh award library');return response.text();})
            .then(function(html){
                var parsed=new DOMParser().parseFromString(html,'text/html');
                var fresh=parsed.getElementById('resultspack-award-library');
                if(!fresh)throw new Error('Award library markup missing');
                current.innerHTML=fresh.innerHTML;
                restoreAwardEditorState(current,savedState);
                // DOM replacement must not disrupt the user's view.
                window.scrollTo(scrollX,scrollY);
                var row=findAwardRow(current,preferredKey);
                if(action==='add'&&row){
                    if(kind==='divider'){
                        var groupToggle=row.querySelector('input[name^="custom_award_group_include_"]');
                        if(groupToggle)groupToggle.checked=true;
                    }else{
                        var includeToggle=row.querySelector('input[name^="custom_award_include_"]');
                        if(includeToggle)includeToggle.checked=true;
                    }
                    updateAwardGroupVisibility();
                    var form=document.getElementById('resultspack-generator');
                    if(form)saveForm(form,form.getAttribute('data-storage-key')||'ianseo-results-pack');
                    focusWithoutScrolling(row.querySelector('[data-award-definition="name"]'));
                }else if(action==='move'&&row){
                    focusWithoutScrolling(row.querySelector('.resultspack-award-move[data-direction="'+(direction==='up'?'up':'down')+'"]'));
                }else if(row){
                    focusWithoutScrolling(row.querySelector('input,button,textarea,select'));
                }
            });
    }

    function awardLibraryRequest(data, refreshAfter){
        var form=document.getElementById('resultspack-generator');
        data.csrf_token=form&&form.elements['csrf_token']?form.elements['csrf_token'].value:'';
        var body=Object.keys(data).map(function(key){return encodeURIComponent(key)+'='+encodeURIComponent(data[key]===undefined?'':data[key]);}).join('&');
        return fetch('AwardLibrary.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8'},body:body,credentials:'same-origin'})
            .then(function(response){return response.json();})
            .then(function(result){
                if(!result||!result.ok){
                    throw new Error(
                        result&&result.error
                            ? result.error
                            : 'The award library update failed.'
                    );
                }
                if(refreshAfter)return refreshAwardLibrary(result.key||data.key||'',data.action||'',data.direction||'',data.kind||'');
                return result;
            })
            .catch(function(error){
                window.alert(
                    'The custom award library could not be updated.\n\n'
                    + error.message
                    + '\n\nYour entered text is still visible. Correct it before reloading.'
                );
            });
    }

    function awardRow(target){return target&&target.closest?target.closest('[data-award-key]'):null;}

    function saveAwardDefinition(input){
        var row=awardRow(input);
        if(!row||!row.getAttribute('data-award-key')||input.readOnly)return;
        var values={action:'save',key:row.getAttribute('data-award-key'),name:'',description:'',category:''};
        row.querySelectorAll('[data-award-definition]').forEach(function(field){values[field.getAttribute('data-award-definition')]=field.value;});
        awardLibraryRequest(values,false);
    }

    document.addEventListener('click',function(event){
        var target=event.target;
        if(!target)return;
        if(target.id==='resultspack-award-add'){
            event.preventDefault();
            awardLibraryRequest({action:'add',kind:'award'},true);
            return;
        }
        if(target.id==='resultspack-award-add-divider'){
            event.preventDefault();
            awardLibraryRequest({action:'add',kind:'divider'},true);
            return;
        }
        if(target.classList.contains('resultspack-award-move')){
            event.preventDefault();
            var moveRow=awardRow(target);
            if(moveRow){
                awardLibraryRequest({action:'move',key:moveRow.getAttribute('data-award-key'),direction:target.getAttribute('data-direction')||'down'},true);
            }
            return;
        }
        if(target.classList.contains('resultspack-award-delete')){
            event.preventDefault();
            var deleteRow=awardRow(target);
            if(deleteRow&&window.confirm('Remove this item from the shared custom award library?')){
                awardLibraryRequest({action:'delete',key:deleteRow.getAttribute('data-award-key')},true);
            }
            return;
        }
        if(target.id==='resultspack-select-all'){event.preventDefault();setTournamentSelection(true);}
        if(target.id==='resultspack-deselect-all'){event.preventDefault();setTournamentSelection(false);}
        if(target.classList.contains('resultspack-check-all')){event.preventDefault();setNamedSelection(target.getAttribute('data-name'),true);}
        if(target.classList.contains('resultspack-uncheck-all')){event.preventDefault();setNamedSelection(target.getAttribute('data-name'),false);}
        if(target.classList.contains('resultspack-reset-default')){
            event.preventDefault();
            var form=document.getElementById('resultspack-generator');
            var field=form?form.elements[target.getAttribute('data-field')]:null;
            if(field&&field.getAttribute('data-default')!==null){
                field.value=field.getAttribute('data-default');
                autoGrowTextarea(field);
                field.focus();
                if(form)saveForm(form,form.getAttribute('data-storage-key'));
            }
        }
    });

    document.addEventListener('input',function(event){
        if(event.target&&event.target.classList&&event.target.classList.contains('resultspack-autogrow'))autoGrowTextarea(event.target);
    });

    document.addEventListener('change',function(event){
        if(event.target&&event.target.hasAttribute('data-award-definition'))saveAwardDefinition(event.target);
        if(event.target&&event.target.classList.contains('resultspack-mode-select'))updateMode(event.target);
        if(event.target&&
            (event.target.id==='resultspack-weather-indoor'||
            event.target.id==='resultspack-weather-indoor-na'
            )
        ){
            updateWeather();
        }
        if(event.target&&event.target.name==='record_status')updateRecordStatus();
        if(event.target&&event.target.name==='individual_source')updateIndividualSource();
        if(event.target&&event.target.name==='event_name_same_as_cover')updateEventNameLink();
        if(event.target&&event.target.name==='date_format')applyDateFormat();
        if(event.target&&(['include_individuals','include_teams'].indexOf(event.target.name)!==-1)){
            updateSectionDependencies();
            updateDnsCoverDependency();
        }
        if(event.target&&event.target.name==='include_dns')updateDnsCoverDependency();
        if(event.target&&event.target.name==='include_custom_awards')updateCustomAwardsVisibility();
        if(event.target&&event.target.name&&event.target.name.indexOf('custom_award_group_include_')===0)updateAwardGroupVisibility();
    });

    document.addEventListener('DOMContentLoaded',function(){
        var form=document.getElementById('resultspack-generator');
        if(!form){refreshUi();return;}
        var key=form.getAttribute('data-storage-key')||'ianseo-results-pack';
        restoreForm(form,key);
        settingsLastSaved=JSON.stringify({version:2,fields:serialiseForm(form)});
        installSettingsControls(form,key);
        refreshUi();
        resizeAllAutoGrow();

        var coverTitle=form.elements['cover_title'];
        if(coverTitle){
            coverTitle.addEventListener('input',function(){
                var same=form.elements['event_name_same_as_cover'];
                if(same&&same.checked)updateEventNameLink();
            });
        }

        ['include_individuals','include_teams'].forEach(function(name){
            var master=form.elements[name];
            if(master){
                master.addEventListener('change',function(){updateSectionDependencies();updateDnsCoverDependency();});
                master.addEventListener('click',function(){updateSectionDependencies();updateDnsCoverDependency();});
            }
        });
        var dnsMaster=form.elements['include_dns'];
        if(dnsMaster){
            dnsMaster.addEventListener('change',updateDnsCoverDependency);
            dnsMaster.addEventListener('click',updateDnsCoverDependency);
        }

        var saveSoon=function(){saveForm(form,key);};
        form.addEventListener('input',saveSoon);
        form.addEventListener('change',saveSoon);
        // Save while the page is active; beforeunload warns about unconfirmed changes.

        form.addEventListener('submit',function(event){
            var sectionNames=['include_cover','include_individuals','include_teams','include_finals'];
            var anySection=sectionNames.some(function(name){var el=form.elements[name];return el&&el.checked;});
            var awardsMaster=form.elements['include_custom_awards'];
            var anyAward=false;
            if(awardsMaster&&awardsMaster.checked){
                var groupIncluded=true;
                var library=document.getElementById('resultspack-award-library');
                if(library){
                    Array.prototype.some.call(library.children,function(item){
                        if(item.classList.contains('resultspack-award-divider')){
                            var groupToggle=item.querySelector('input[name^="custom_award_group_include_"]');
                            groupIncluded=!groupToggle||groupToggle.checked;
                            return false;
                        }
                        if(!groupIncluded||!item.classList.contains('resultspack-award-row'))return false;
                        var box=item.querySelector('input[name^="custom_award_include_"]');
                        if(!box||!box.checked)return false;
                        var suffix=box.name.substring('custom_award_include_'.length);
                        var winner=form.elements['custom_award_winner_'+suffix];
                        if(winner&&String(winner.value||'').trim()!==''){anyAward=true;return true;}
                        return false;
                    });
                }
            }
            if(!anySection&&!anyAward){
                event.preventDefault();
                window.alert('Please select at least one PDF section or completed custom award.');
                return;
            }
            saveForm(form,key);
        });
    });
})();

(() => {
    document.addEventListener('click', async (event) => {
        const button = event.target.closest(
            '#resultspack-use-archeryweather'
        );

        if (!button || button.disabled) return;

        const form = document.getElementById('resultspack-generator');
        const select = document.getElementById(
            'resultspack-archeryweather-session'
        );
        const feedback = document.getElementById(
            'resultspack-archeryweather-feedback'
        );

        if (!form || !select || !feedback) return;

        const message = (text, error = false) => {
            feedback.textContent = text;
            feedback.style.color = error ? '#a12622' : '#245b2a';
        };

        if (!select.value) {
            message('Please choose a weather session first.', true);
            return;
        }

        const fields = [
            'weather_notes',
            'weather_temperature',
            'weather_wind_speed',
            'weather_humidity'
        ].map((name) => form.elements[name]);

        if (fields.some((field) => !field)) {
            message('The weather fields could not be found.', true);
            return;
        }

        const originals = fields.map((field) => field.value);
        const [notes, temperature, wind, humidity] = fields;

        const url = new URL(
            'ArcheryWeatherSummary.php',
            window.location.href
        );

        url.searchParams.set(
            'tournament_id',
            button.dataset.tournamentId
        );
        url.searchParams.set('session_id', select.value);

        const controller = new AbortController();
        const timeout = window.setTimeout(
            () => controller.abort(),
            20000
        );

        button.disabled = true;
        select.disabled = true;
        button.textContent = 'Loading summary…';
        message('Reading stored ArcheryWeather observations…');

        try {
            const response = await fetch(url, {
                credentials: 'same-origin',
                cache: 'no-store',
                signal: controller.signal
            });

            const data = await response.json();

            if (!response.ok || !data.ok) {
                throw new Error(
                    data.error || 'The weather summary could not be loaded.'
                );
            }

            if (
                typeof data.summary !== 'string'
                || !(
                    data.humidity_average === null
                    || (
                        typeof data.humidity_average === 'number'
                        && Number.isFinite(data.humidity_average)
                    )
                )
            ) {
                throw new Error('The server returned an invalid summary.');
            }

            if (fields.some((field, index) =>
                field.value !== originals[index]
            )) {
                throw new Error(
                    'The weather fields were edited while loading. '
                    + 'Press the button again if you want to replace those edits.'
                );
            }

            notes.value = data.summary;
            temperature.value = '';
            wind.value = '';
            humidity.value = data.humidity_average === null
                ? ''
                : String(Math.round(data.humidity_average));

            const indoorNoData = form.elements['weather_indoor_na'];

            if (indoorNoData) {
                indoorNoData.checked = false;
                indoorNoData.dispatchEvent(
                    new Event('change', { bubbles: true })
                );
            }

            // Use the existing form-saving and textarea-resizing handlers.
            fields.forEach((field) => {
                field.dispatchEvent(
                    new Event('input', { bubbles: true })
                );
                field.dispatchEvent(
                    new Event('change', { bubbles: true })
                );
            });

            message(
                'Summary copied from session #' + data.session_id
                + ' (' + data.observation_count + ' observations). '
                + 'You can edit the text below.'
            );
        } catch (error) {
            const explanation = error.name === 'AbortError'
                ? 'The request timed out.'
                : error instanceof SyntaxError
                    ? 'The server did not return a valid summary.'
                    : error.message;

            message(
                explanation + ' Your existing weather text has been kept.',
                true
            );
        } finally {
            window.clearTimeout(timeout);
            button.disabled = false;
            select.disabled = false;
            button.textContent = 'Use ArcheryWeather summary';
        }
    });
})();