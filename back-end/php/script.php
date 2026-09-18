<?php

// faz o php mostrar erros nas respostas caso haja algum erro
ini_set('display_errors', 1);
error_reporting(E_ALL);

// chama o arquivo que faz a conexão com o banco de dados
require_once 'config.php';

// informa que a resposta será enviada em json
header('Content-Type: application/json');

// coleta e guarda os valores enviados pelo js
extract($_POST);

//chama a função que o js mandar
switch ($acao) {
    
    case 'listarBases':
        listarBases($conn);
        break;

    case 'listarPackages':
        listarPackages();
        break;

    case 'packageShow':
        packageShow();
        break;

    case 'criarBasesFontes':
        criarBasesFontes($conn);
        break;

    // caso a ação seja inválida
    default:
        echo json_encode([
            "erro" => "Ação inválida."
        ]);
        break;
}

// função que vai listar as bases
function listarBases($conn) {

    // salva a consulta para encontrar as bases
    $sql = "
        SELECT
            id_base,
            nome
        FROM agade_software.bases
        ORDER BY nome
    ";

    // executa a consulta e guarda os resultados
    $resultado = pg_query($conn, $sql);

    // verifica se a consulta deu certo
    if(!$resultado){
        echo json_encode([
            "erro" => "Erro ao buscar as bases.",
            "detalhes" => pg_last_error($conn)
        ]);
        return;
    }

    // transforma os registros em uma array
    $bases = pg_fetch_all($resultado);

    // devolve para o js
    echo json_encode([
        "success" => true,
        "bases" => $bases
    ]);
}

// função que vai listar os packages
function listarPackages(){
    // guarda as variáveis do post dentro da função
    extract($_POST);

    // monta a url da api do ckan que lista os packages
    $api = $url . '/api/3/action/package_list';

    // chama a função que faz a requisição e guarda o resultado
    $resultado = requisicaoCKAN($api);

    // verifica o resultado da requisição
    if(!$resultado){
        return;
    }

    // devolve para o js
    echo json_encode($resultado);
}

function packageShow(){
    // guarda as variáveis do post dentro da função
    extract($_POST);

    // monta a url da api do ckan que busca os dados do package
    $api = $url . '/api/3/action/package_show?id=' . urlencode($id);

    // chama a função que faz a requisição e guarda o resultado
    $resultado = requisicaoCKAN($api);

    // verifica o resultado da requisição
    if(!$resultado){
        return;
    }

    // devolve para o js
    echo json_encode($resultado);
}

// função que faz a requisição ckan
function requisicaoCKAN(string $url){
    // inicia a requisição
    $ch = curl_init($url);

    // configura a requisição
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Agade Software');
    // desativa a verificação do certificado SSL (TEMPORÁRIO)
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

    // executa a requisição
    $json = curl_exec($ch);

    // verifica se houve erro na requisição
    if(curl_errno($ch)){
        echo json_encode([
            "erro" => curl_error($ch)
        ]);
        curl_close($ch);
        return null;
    }

    // encerra o cURL
    curl_close($ch);

    // converte a resposta em um array e devolve para quem chamou
    return json_decode($json, true);
}

function criarBasesFontes($conn) {
    // guarda as variáveis do post dentro da função
    extract($_POST);

    // verifica se php recebeu os dados (ver se realmente precisa)
    if(empty($dados)){
        echo json_encode([
            "erro" => "Dados não enviados."
        ]);
        return;
    }

    // transforma os dados em um array associativo do php
    $dados = json_decode($dados, true);

    // verifica se o php conseguiu converter os dados do js
    if(!$dados){
        echo json_encode([
            "erro" => "Erro ao interpretar os dados."
        ]);
        return;
    }

    // Inicia a transação
    pg_query($conn, "BEGIN");

    // cria o script de inserir a base e pegar o id_base gerado
    $sql_base = "
        INSERT INTO agade_software.bases
        (
            nome,
            descricao,
            tabela_destino,
            fonte,
            fonte_link,
            fonte_api
        )
        VALUES
        (
            $1,
            $2,
            $3,
            $4,
            $5,
            $6
        )
        RETURNING id_base
    ";

    // executa o script com os dados que o js mandou
    $resultado = pg_query_params($conn, $sql_base, [
        $dados['nome'],
        $dados['descricao'],
        $dados['tabela_destino'],
        $dados['fonte'],
        $dados['fonte_link'],
        $dados['fonte_api']
    ]);

    // guarda o id_base
    $id_base = pg_fetch_result($resultado, 0, 'id_base');

    // verifica se o insert funcionou
    if(!$resultado){
        pg_query($conn, "ROLLBACK");
        echo json_encode([
            "erro" => "Erro ao inserir a base.",
            "detalhes" => pg_last_error($conn)
        ]);
        return;
    };

    // monta o script para inserir as fontes
    $sql_fonte = "
        INSERT INTO agade_software.fontes
        (
            resource_id,
            url,
            nome,
            ultima_atualizacao,
            package_id,
            delimitador,
            id_base
        )
        VALUES
        (
            $1,
            $2,
            $3,
            $4,
            $5,
            $6,
            $7
        )
    ";

    // executa o insert da fonte com cada resource selecionado
    foreach($dados['resources'] as $resource){
        $resultado = pg_query_params($conn, $sql_fonte, [
            $resource['resource_id'],
            $resource['url'],
            $resource['nome'],
            $resource['ultima_atualizacao'],
            $resource['package_id'],
            $resource['delimitador'],
            $id_base
        ]);

        // verifica se algum insert de resource falhou
        if(!$resultado){
            pg_query($conn, "ROLLBACK");
            echo json_encode([
                "erro" => "Erro ao inserir um resource.",
                "resource_id" => $resource['resource_id'],
                "detalhes" => pg_last_error($conn)
            ]);
            return;
        }
    }

    // Confirma todas as inserções
    pg_query($conn, "COMMIT");

    echo json_encode([
        "success" => true,
        "mensagem" => "Base e fontes inseridas com sucesso."
    ]);
}

?>