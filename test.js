// transfo un mot en tableau

var string = "ma superbe superbe. String string géniale un deux un un trois trois";
console.log(stringToTab(string));

function stringToTab(string){
    var tab = [];
    var mot = "";
    var numeroMot = 0;
    var motPrecedant = "";
    for(var i=0; i <= string.length; i++){
        if(string.charAt(i) != " " && string.charAt(i) != "."){
            mot += string.charAt(i);
        }else{
            if(mot != motPrecedant){
                tab[numeroMot] = mot;
                numeroMot++;
            }
            motPrecedant = mot;
            mot = "";
        }
    }

    return tab;
}

var tab = ["ma", "superbe", "string", "géniale", "un", "deux", "un", "trois"];
tabToString(tab);

function tabToString(tab){
    var string = "";
    for(var i=0; i < tab.length; i++){
        if(i==0){
            string += tab[i];
        }else{
            string += " " + tab[i];
        }

    }
    return string;
}

function fibonacci(n){

}