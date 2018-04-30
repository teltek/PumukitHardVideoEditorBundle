function stringToFloat(aDateString){
    tt=aDateString.split(":");
    sec=tt[0]*3600+tt[1]*60+tt[2]*1+tt[3]*0.001;
    return sec;
}
var Videoeditor = Class.create({


    initialize: function(video, controls, visualizadores, marcadores){
        that = this;
        this.TRIM_STP=1.0;//Time in seconds. It is the time that is applied to stuff when you click the arrows
        this.pop = Popcorn(video);
        this.duration = this.pop.duration();
        this.timer = document.getElementById("cTimeText");
        //Pic Extrator
        this.video = video;
        this.img = document.getElementById("img");
        this.upload_img = document.querySelector("#file-upload-button");
        this.canvas_draw = document.getElementById("canvas-draw-frames");
        this.ctx_draw = this.canvas_draw.getContext("2d");
        this.init_img = this.img.src;
        this.img_input = document.getElementById("hidden_src_img");
        this.clear_pic = document.getElementById("clear_pic");
        this.clear_meta = document.getElementById("clear_meta");
        this.title = document.getElementById("cut_title_es");
        this.desc = document.getElementById("cut_descript_es");
        this.comm = document.getElementById("cut_comment");
        this.pers = document.getElementById("cut_person");
        this.check = document.getElementById("nuevo_participante");
        this.newPers = document.getElementById("cut_newperson");
        this.divparticipante= document.getElementById("cut_post");
        this.puesto = document.getElementById("cut_puesto_es");
        this.empresa = document.getElementById("cut_firm_es");
        this.caja_in = document.getElementById('in');
        this.caja_out = document.getElementById('out');
        this.timepicker_left = document.getElementById('timepicker_left');
        this.timepicker_right = document.getElementById('timepicker_right');

        //visualizadores de tiempo

        this.in_ms = this.createNode(visualizadores, 'input', 'in_ms', 'hidden');
        this.in_ms.setAttribute('name', 'in_ms');
        this.in_ms.value = 0;

        this.out_ms = this.createNode(visualizadores, 'input', 'out_ms', 'hidden');
        this.out_ms.setAttribute('name', 'out_ms');
        this.out_ms.value = this.duration;


        this.pop.on("timeupdate", function() {
            seeker.slider('setValue', that.pop.currentTime());
            ms = (that.pop.currentTime().toFixed(3)).toString().replace(/^[^\.]+/, '');
            that.timer.innerHTML = new Date(that.pop.currentTime().toFixed(3) * 1000).toUTCString().replace(/.*(\d{2}:\d{2}:\d{2}).*/g, "$1") + ms ;

        });


        //made range element
        this.range = this.createNode(video.parentNode, 'input', 'sl1', 'range', null, {'width':'780px'});

        var seeker = $(this.range);
        seeker.slider({min: 0,
                       max: this.duration,
                       step: 0.01,
                       value: 0,
                       tooltip: 'hide'
                      }).on('slide', function(ev){
                          /*
                               javascript hack: use that!!!
                               this.pop.currentTime(parseFloat(ev.value).toFixed(3));
                          */
                          that.pop.currentTime(parseFloat(ev.value).toFixed(3));
                      });

        //ALFRO: precision buttons inicio trim
        //crea los elementos de corte
        this.slider = this.createNode(controls, 'input', 'sl2', null, null, {'width': '766px'});
        this.slider.value = [0, this.duration];
        var tbars = $(this.slider);
        tbars.slider({range: true,
                      min: 0,
                      max: this.duration,
                      step: 0.01,
                      value: [ 0, this.duration ],
                      handle: 'triangle'
                     }).on('slide', function(ev){
                         if( parseFloat(that.timeToMiliseconds(that.timepicker_left.value)).toFixed(3) != parseFloat(ev.value[0]).toFixed(3) ) {
                             that.pop.currentTime(ev.value[0].toFixed(3));
                         }
                         else {
                             that.pop.currentTime(parseFloat(ev.value[1]).toFixed(3));
                         }

                         $('#timepicker_left').timepicker('setTime', new Date((ev.value[0].toFixed(3) -3600) * 1000));
                         that.in_ms.value=stringToFloat(that.timepicker_left.value);
                         $('#timepicker_right').timepicker('setTime', new Date((ev.value[1].toFixed(3) -3600) * 1000));
                         that.out_ms.value=stringToFloat(that.timepicker_right.value);
                     });
        var slvalue = that.slider.value.split(',');
        $('#timepicker_left').timepicker('setTime', new Date((parseFloat(slvalue[0])-3600)*1000));
        this.in_ms.value=stringToFloat(that.timepicker_left.value);
        $('#timepicker_right').timepicker('setTime', new Date((parseFloat(slvalue[1])-3600)*1000));
        this.out_ms.value=stringToFloat(that.timepicker_right.value);

        //ALFRO: precision buttons final trim
        //crea los botones
        this.controlbar = this.createNode(controls, 'div', 'controlbar', null, 'editorToolbarPlaybackControls');

        //boton de deshacer
        this.undo = this.createNode(this.controlbar, 'button', 'undo', 'button', 'btn btn-small', {"margin": "0px 5px 4px 4px"});
        this.undo.setAttribute('title', 'Undo cut'); //Deshacer el corte
        im1 = this.createNode(this.undo, 'i', null, null, 'icon-white icon-repeat');
        this.undo.innerHTML = this.undo.innerHTML + " Undo"

        //boton de previsualizar inicial del trim
        this.trimin = this.createNode(this.controlbar, 'button', 'trimii', 'button', 'btn btn-small', {"margin": "0px 4px 4px 5px"});
        this.trimin.setAttribute('title', 'Preview start cut');
        im2 = this.createNode(this.trimin, 'i', null, null, 'icon-white icon-eye-open');
        this.trimin.innerHTML = this.trimin.innerHTML + " Prev Start"


        //botones de reproduccion
        //cada una de la imagenes que corresponden a cada boton por orden de creacion
        clases = ["icon-fast-backward", "icon-step-backward", "icon-backward", "icon-chevron-left", "icon-play",  "icon-pause", "icon-chevron-right", "icon-forward", "icon-step-forward", "icon-fast-forward"];
        titles = ["Inicio del vídeo", "Punto inicial de corte", "Retrocede un segundo", "Frame anterior", "Play", "Pausa", "Frame siguiente", "Avanza un segundo", "Punto final de corte", "Fin del vídeo"];
        ids = ["backward", "incorte",  "prevs", "previousFrame", "play", "pause", "nextFrame", "nexts", "ficorte", "forward"]
        this.btn_group = this.createNode(this.controlbar, 'div', 'btn-group', null, 'btn-group', 'button', 'btn btn-small');

        this.buttons = {};
        for (var i = 0; i < 10; i++) {
            this.buttons['button' + i] = this.createNode(this.btn_group, 'button', ids[i], 'button', 'btn btn-small');
            this.buttons['button' + i].setAttribute('title', titles[i]);
            img_i = this.createNode(this.buttons['button' + i], 'i', null, null, 'icon-white '+ clases[i]);
            this.buttons['button' + i].innerHTML = this.buttons['button' + i].innerHTML + "&nbsp";
        }


        //boton de previsualizar final del trim
        this.trimfi = this.createNode(this.controlbar, 'button', 'trimfi', 'button', 'btn btn-small', {"margin": "0px 4px 4px 5px"});
        this.trimfi.setAttribute('title', 'Preview end cut');
        im2 = this.createNode(this.trimfi, 'i', null, null, 'icon-white icon-eye-open');
        this.trimfi.innerHTML = this.trimfi.innerHTML + " Prev end"

        //boton de previsualizar trim
        this.trim = this.createNode(this.controlbar, 'button', 'trim', 'button', 'btn btn-small', {"margin": "0px 4px 4px 5px"});
        this.trim.setAttribute('title', 'Cut preview');
        im2 = this.createNode(this.trim, 'i', null, null, 'icon-white icon-eye-open');
        this.trim.innerHTML = this.trim.innerHTML + " Preview"

        //boton de tomar imagen pic
        this.pic = this.createNode(this.controlbar, 'button', 'pic', 'button', 'btn btn-small', {"margin": "0px 4px 4px 5px"});
        this.pic.setAttribute('title', 'Take picture for multimedia object');
        im2 = this.createNode(this.pic, 'i', null, null, 'icon-white icon-picture');
        this.pic.innerHTML = this.pic.innerHTML + " Take picture"

        this.undo.addEventListener("click", function(e){
            that.pop.pause();
            eventos = that.pop.getTrackEvents();
            eventos.forEach(that.deleteTrackEvents);
            that.pop.currentTime(0);
            tbars.slider('setValue', [0, that.duration]);
            that.slider.value = [0, that.duration];
            that.buttons.button4.disabled = false;
            that.buttons.button5.disabled = false;

            $('#timepicker_left').timepicker('setTime', new Date(0-3600*1000));
            that.in_ms.value=stringToFloat(that.timepicker_left.value);
            $('#timepicker_right').timepicker('setTime', new Date((that.duration-3600)*1000));
            that.out_ms.value=stringToFloat(that.timepicker_right.value);

            document.getElementsByClassName("alert")[0].style.visibility='hidden';//Doesn't exists


        }, false);

        this.trimin.addEventListener("click", function(e){
            eventos = that.pop.getTrackEvents();
            eventos.forEach(that.deleteTrackEvents);
            that.pop.currentTime(that.timeToMiliseconds(that.timepicker_left.value));
            that.pop.cue((parseFloat(that.timeToMiliseconds(that.timepicker_left.value)) + 10), that.preview);
            that.pop.play();
            that.buttons.button4.disabled = true;
            that.buttons.button5.disabled = false;
        }, false);


        this.trimfi.addEventListener("click", function(e){
            eventos = that.pop.getTrackEvents();
            eventos.forEach(that.deleteTrackEvents);
            that.pop.currentTime(parseFloat(that.timeToMiliseconds(that.timepicker_right.value)- 10) );
            that.pop.cue(that.timeToMiliseconds(that.timepicker_right.value), that.preview);
            that.pop.play();
            that.buttons.button4.disabled = true;
            that.buttons.button5.disabled = false;
        }, false);

        this.trim.addEventListener("click", function(e){
            eventos = that.pop.getTrackEvents();
            eventos.forEach(that.deleteTrackEvents);
            that.pop.currentTime(that.in_ms.value);
            that.pop.cue(that.out_ms.value, that.preview);
            that.pop.play();
            that.buttons.button4.disabled = true;
            that.buttons.button5.disabled = false;
        }, false);


        this.pic.addEventListener("click", function(e){
            that.ctx_draw.drawImage(that.video, 0, 0, that.video.width, that.video.height);
            that.img.src = that.canvas_draw.toDataURL("image/jpeg");
            that.img_input.value = that.canvas_draw.toDataURL("image/jpeg");
            that.clear_pic.show();
        }, false);

        this.upload_img.addEventListener("change", function(e){
            var file = e.target.files[0];
            var reader = new FileReader();
            reader.onload = function(e) {
                // Render thumbnail.
                that.img.src = e.target.result;
                that.img_input.value = e.target.result;
                that.clear_pic.show();
            };
            reader.readAsDataURL(file);
        }, false);



        this.clear_pic.addEventListener("click", function(e){
            that.img.src = that.init_img;
            that.img_input.value = "";
            that.clear_pic.hide();
        }, false);


        this.clear_meta.addEventListener("click", function(e){
            if ($('#new_person').is(":checked")){
                that.newPers.hide();
                that.divparticipante.hide();
                that.pers.show();
            }
            that.img.src = that.init_img;
            that.img_input.value = "";
            that.clear_pic.hide();
            document.forms[0].reset();
        }, false);


        this.buttons.button0.addEventListener("click", function(e){
            that.pop.currentTime(0);
        }, false);

        this.buttons.button1.addEventListener("click", function(e){
            that.pop.currentTime(that.in_ms.value);
        }, false);

        this.buttons.button2.addEventListener("click", function(e){
            that.pop.currentTime(that.pop.currentTime() - 1);
        }, false);

        this.buttons.button3.addEventListener("click", function(e){
            //retrocede un frame asumiendo 25fps
            that.pop.currentTime(that.pop.currentTime() - 0.04);
        }, false);

        this.buttons.button4.addEventListener("click", function(e){
            that.pop.play();
            that.buttons.button5.disabled = false;
            that.buttons.button4.disabled = true;
        }, false);

        this.buttons.button5.addEventListener("click", function(e){
            that.pop.pause();
            that.buttons.button5.disabled = true;
            that.buttons.button4.disabled = false;
        }, false);

        this.buttons.button6.addEventListener("click", function(e){
            //avanza un frame asumiendo 25fps
            that.pop.currentTime(that.pop.currentTime() + 0.04);
        }, false);

        this.buttons.button7.addEventListener("click", function(e){
            that.pop.currentTime(that.pop.currentTime() + 1);
        }, false);

        this.buttons.button8.addEventListener("click", function(e){
            that.pop.currentTime(that.out_ms.value);
        }, false);

        this.buttons.button9.addEventListener("click", function(e){
            that.pop.currentTime(that.duration);
        }, false);

        //hacemos que cambien los botones al acabar el vídeo
        this.pop.on("ended", function(){
            that.buttons.button5.disabled = true;
            that.buttons.button4.disabled = false;
        });


        //ALFRO: timepickers
        this.timepicker_left.addEventListener("keydown", function(e) {
            var slvalue = that.slider.value.split(',');
            that.pop.currentTime(parseFloat(that.timeToMiliseconds(that.timepicker_left.value)).toFixed(3));
            tbars.slider('setValue', [parseFloat(that.timeToMiliseconds(that.timepicker_left.value)).toFixed(3), parseFloat(slvalue[1]).toFixed(3)]);
            that.slider.value = [parseFloat(that.timeToMiliseconds(that.timepicker_left.value)).toFixed(3), parseFloat(slvalue[1]).toFixed(3)];
            that.pop.pause();
            if (that.buttons.button4.disabled) {
                that.buttons.button4.disabled = false;
                that.buttons.button5.disabled = true;
            }
            if(e.keyCode == 13){
                $('#timepicker_left').timepicker('setTime', that.timepicker_left.value);
                that.in_ms.value=stringToFloat(that.timepicker_left.value);
            }
        }, false);
        this.timepicker_right.addEventListener("keydown", function(e) {
            var slvalue = that.slider.value.split(',');
            that.pop.currentTime(parseFloat(that.timeToMiliseconds(that.timepicker_right.value)).toFixed(3));
            tbars.slider('setValue', [parseFloat(slvalue[0]).toFixed(3), parseFloat(that.timeToMiliseconds(that.timepicker_right.value)).toFixed(3)]);
            that.slider.value = [parseFloat(slvalue[0]).toFixed(3), parseFloat(that.timeToMiliseconds(that.timepicker_right.value)).toFixed(3)];
            that.pop.pause();
            if (that.buttons.button4.disabled) {
                that.buttons.button4.disabled = false;
                that.buttons.button5.disabled = true;
            }
            if(e.keyCode == 13){
                $('#timepicker_right').timepicker('setTime', that.timepicker_right.value);
                that.out_ms.value=stringToFloat(that.timepicker_right.value);
            }
        }, false);

        this.timepicker_left.addEventListener("focusout", function(e) {
            that.in_ms.value=stringToFloat(that.timepicker_left.value);
            if (parseFloat(that.in_ms.value) > parseFloat(that.out_ms.value)) {
              $('#timepicker_left').timepicker('setTime', new Date((that.out_ms.value-3600)*1000));
              that.in_ms.value=stringToFloat(that.timepicker_left.value);
            }
        }, false);

        this.timepicker_right.addEventListener("focusout", function(e) {
            that.out_ms.value=stringToFloat(that.timepicker_right.value);
            if (parseFloat(that.out_ms.value) > that.duration) {
              $('#timepicker_right').timepicker('setTime', new Date((that.duration-3600)*1000));
              that.out_ms.value=stringToFloat(that.timepicker_right.value);
            }
        }, false);


        //botones de marcado
        this.dsetin = this.createNode(marcadores, 'div', null, null, null, {"float": "left",
                                                                            "width": "390px"});
        this.spin = this.createNode(this.dsetin, 'span');
        this.setin =  this.createNode(this.spin, 'button', 'setin', 'button', 'btn btn-info');
        this.setin.innerHTML = "Set start";


        this.dsetout = this.createNode(marcadores, 'div', null, null, null, {"float": "right",
                                                                             "width": "390px"});
        this.spout = this.createNode(this.dsetout, 'span');
        this.setout =  this.createNode(this.spout, 'button', 'setout', 'button', 'btn btn-info');
        this.setout.innerHTML = "Set end";

        //eventos de los botones de marcado
        this.setin.addEventListener("click", function(e){
            that.pop.pause();
            var slvalue = that.slider.value.split(',');
            tbars.slider('setValue', [that.pop.currentTime().toFixed(3), parseFloat(slvalue[1]).toFixed(3)]);
            that.slider.value = [that.pop.currentTime().toFixed(3), parseFloat(slvalue[1]).toFixed(3)];
            if (that.buttons.button4.disabled) {
                that.buttons.button4.disabled = false;
                that.buttons.button5.disabled = true;
            }
            $('#timepicker_left').timepicker('setTime', new Date((that.pop.currentTime().toFixed(3)-3600) * 1000));
            that.in_ms.value=stringToFloat(that.timepicker_left.value);
            if(parseFloat(that.out_ms.value) < parseFloat(that.in_ms.value)) {
                temp_value=that.in_ms.value;
                that.in_ms.value=that.out_ms.value;
                that.out_ms.value=temp_value;
                temp_value=that.timepicker_right.value;
                $('#timepicker_right').timepicker('setTime',that.timepicker_left.value);
                $('#timepicker_left').timepicker('setTime',temp_value);
            }

        }, false);


        this.setout.addEventListener("click", function(e){
            that.pop.pause();
            var slvalue = that.slider.value.split(',');
            tbars.slider('setValue', [parseFloat(slvalue[0]).toFixed(3), that.pop.currentTime().toFixed(3)]);
            that.slider.value = [parseFloat(slvalue[0]).toFixed(3), that.pop.currentTime().toFixed(3)];
            if (that.buttons.button4.disabled) {
                that.buttons.button4.disabled = false;
                that.buttons.button5.disabled = true;
            }
            $('#timepicker_right').timepicker('setTime', new Date((that.pop.currentTime().toFixed(3)-3600) * 1000));
            that.out_ms.value=stringToFloat(that.timepicker_right.value);
            if(parseFloat(that.out_ms.value) < parseFloat(that.in_ms.value)) {
                temp_value=that.in_ms.value;
                that.in_ms.value=that.out_ms.value;
                that.out_ms.value=temp_value;
                temp_value=that.timepicker_right.value;
                $('#timepicker_right').timepicker('setTime',that.timepicker_left.value);
                $('#timepicker_left').timepicker('setTime',temp_value);
            }
        }, false);


        if(this.video.readyState == 4){
            this.init_extractpic();
        }else{
            this.video.addEventListener("loadeddata", this.init_extractpic, false);
        }

    },

    move_slider: function(time,tbar,move){
        that.pop.pause();
        var slvalue = that.slider.value.split(',');
        switch(move){
        case 'init':
            if(!(slvalue[0]-(-time)>slvalue[1])) {
                tbar.slider('setValue', [that.pop.currentTime().toFixed(3), parseFloat(slvalue[1]).toFixed(3)]);
                that.slider.value = [that.pop.currentTime().toFixed(3), parseFloat(slvalue[1]).toFixed(3)];
                $('#timepicker_left').timepicker('setTime', new Date((that.pop.currentTime().toFixed(3)-3600) * 1000));
                that.in_ms.value=stringToFloat(that.timepicker_left.value);
            }
            break;
        case 'end':
            if(!(slvalue[1]-(-time)<slvalue[0])) {
                tbar.slider('setValue', [parseFloat(slvalue[0]).toFixed(3), that.pop.currentTime().toFixed(3)]);
                that.slider.value = [parseFloat(slvalue[0]).toFixed(3), that.pop.currentTime().toFixed(3)];
                $('#timepicker_right').timepicker('setTime', new Date((that.pop.currentTime().toFixed(3)-3600) * 1000));
                that.out_ms.value=stringToFloat(that.timepicker_right.value);
            }
            break;
        }
        if (that.buttons.button4.disabled) {
            that.buttons.button4.disabled = false;
            that.buttons.button5.disabled = true;
        }
    },

    init_extractpic: function(){
        that.video.width = that.canvas_draw.width = that.video.videoWidth;
        that.video.height = that.canvas_draw.height = that.video.videoHeight;
    },



    //style = {"":"", "":""}
    //createNode('#playerContainer', 'div', 'paellaEditorBottomBar', "{background-color: white}")
    createNode: function(parent, elementType, id, type, className, style) {
        this.parent = $(parent);
        this.domElement = document.createElement(elementType);
        if ( id ) this.domElement.id = id;
        this.parent.append(this.domElement);
        if ( type ) this.domElement.setAttribute('type', type);
        if ( className ) this.domElement.className = className;
        if (style) $(this.domElement).css(style);
        return this.domElement;
    },

    timeToSeconds: function(time){
        a = time.split(":");
        seconds = parseFloat(a[2]) + parseInt(a[1]) * 60 + parseInt(a[0]) * 3600;
        return seconds;
    },
    timeToMiliseconds: function(time){
        a = time.split(":");
        miliseconds = parseFloat(a[2]) + parseFloat(a[1]) * 60 + parseInt(a[0]) * 3600 + parseInt(a[3])*0.001;
        return miliseconds;
    },
    deleteTrackEvents: function(element) {
        that.pop.removeTrackEvent(element.id);
    },

    preview: function() {
        that.pop.pause();
        that.pop.removeTrackEvent(that.pop.getLastTrackEventId());
        //that.pop.currentTime(that.retpoint);
        //that.pop.currentTime(that.in_ms.value);
        that.buttons.button4.disabled = false;
        that.buttons.button5.disabled = true;
    }

});
